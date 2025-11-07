<?php
/**
 * This file is part of the MediaWiki extension CIForms.
 *
 * CIForms is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * CIForms is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CIForms.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright © 2021-2024, https://wikisphere.org
 */

use Dompdf\Dompdf;
use MediaWiki\Title\Title;
use PHPMailer\PHPMailer\PHPMailer;

class CIFormsSubmit extends SpecialPage {
	private $dbType;

	public function __construct() {
		// not listed in the special pages index
		parent::__construct( 'CIFormsSubmit', '', false );
	}

	/**
	 * @param string|null $par
	 * @return void
	 */
	public function execute( $par ) {
		// $request = $this->getRequest();
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$user = $this->getUser();
		// $out->addModuleStyles( 'ext.CIForms.validation' );
		// phpcs:ignore MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
		$post = $_POST;
		// $request->getArray( 'data' );
		global $wgEnableEmail;
		global $wgCIFormsSenderEmail;
		global $wgCIFormsSenderName;
		global $wgPasswordSenderName;
		global $wgPasswordSender;
		global $wgSitename;
		if ( empty( $wgCIFormsSenderEmail ) ) {
			$senderEmail = $wgPasswordSender;
			$senderName = $wgPasswordSenderName;
		} else {
			$senderEmail = $wgCIFormsSenderEmail;
			$senderName = $wgCIFormsSenderName;
		}
		if ( !Sanitizer::validateEmail( $senderEmail ) ) {
			$senderEmail = null;
		}
		if ( CIForms::isCaptchaEnabled() ) {
			[ $result, $message, $captcha_message ] =
				$this->check_captcha( $post ) + [ null, null, null ];
			if ( $result === false ) {
				return $this->exit( $out,
					$this->msg( $message, $captcha_message, $senderEmail )
						. ( $senderEmail ? "\040" . $this->msg( 'ci-forms-try-again-message', $senderEmail ) : '' ),
					null, null );
			}
		}
		$form_result = $this->parseForm( $post );
		if ( empty( $form_result['form_values'] ) ) {
			return $this->exit( $out, 'no submission data', null, null );
		}
		$dbr = \CIForms::getDB( DB_REPLICA );
		$this->dbType = $dbr->getType();
		$row_inserted = $this->storeSubmission( $form_result, $username );
		$formSubmit = self::mergeGlobal( 'email-to', $form_result['form_values'], $isLocal );
		// legacy
		if ( empty( $formSubmit ) ) {
			$formSubmit = self::mergeGlobal( 'submit', $form_result['form_values'], $isLocal );
		}
		$submit_valid = [];
		foreach ( $formSubmit as $email ) {
			if ( Sanitizer::validateEmail( $email ) ) {
				$submit_valid[] = $email;
			}
		}
		$success = false;
		if ( !$wgEnableEmail || empty( $submit_valid ) || !class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) || !class_exists( 'Dompdf\Dompdf' ) ) {
			return $this->exit( $out, $this->exit_message( $form_result, $row_inserted, false, false, $success ), $form_result['form_values'], $success );
		}
		$subject = $this->msg( 'ci-forms-email-subject', $form_result['form_values']['title'], $wgSitename );
		$message_body = $this->msg(
			'ci-forms-email-content',
			$form_result['form_values']['title'],
			Title::newFromText( $form_result['form_values']['pagename'] )->getFullURL()
		);
		$message_body .= '<br /><br /><br />' . $this->msg( 'ci-forms-credits' );
		$attachment = $this->createPDF( $form_result, $username, date( 'Y-m-d H:i:s' ) );

		$from = new MailAddress(
			$senderEmail,
			$senderName,
		);

		$filename = $this->msg( 'ci-forms-email-subject', $form_result['form_values']['title'], $wgSitename );

		$result_success = $this->sendEmail( $from, $submit_valid, $subject, $message_body, $filename, $attachment );

		// @see https://www.mediawiki.org/wiki/Topic:Xdy6mfzzqpx4lsu3
		if ( !empty( $user->getEmail() ) ) {
			$this->sendEmail( $from, [ $user->getEmail() ], $subject, $message_body, $filename, $attachment );
		}

		$this->exit( $out, $this->exit_message( $form_result, $row_inserted, true, $result_success, $success ), $form_result['form_values'], $success );
	}

	/**
	 * @param MailAddress $from
	 * @param array $to
	 * @param string $subject
	 * @param string $message_body
	 * @param string $filename
	 * @param string $attachment
	 * @return bool
	 */
	private function sendEmail( $from, $to, $subject, $message_body, $filename, $attachment ) {
		// *** unfortunately we cannot use MediaWiki's
		// UserMailer since it does not support attachments
		if ( class_exists( 'EmailNotifications' )
			&& strtolower( $GLOBALS['wgCIFormsMailer'] ) == 'emailnotifications'
		) {
			$errors = [];
			$attachments = [
				[
					'body' => $attachment,
					'name' => $filename,
					'contentType' => 'application/pdf'
				]
			];
			$headers = [];
			if ( !empty( $GLOBALS['wgUserEmailUseReplyTo'] ) && !empty( $this->getUser()->getEmail() ) ) {
				$headers['Reply-To'] = [ $this->getUser()->getEmail() ];
			}
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			EmailNotifications::sendEmail(
				$headers,
				$to,
				$from,
				$subject,
				null,
				$message_body,
				$attachments,
				$errors
			);

			if ( count( $errors ) ) {
				foreach ( $errors as $msg ) {
					// @phan-suppress-next-line PhanUndeclaredClassStaticProperty
					EmailNotifications::$Logger->error( $msg );
				}
			}

			return !count( $errors );
		}

		// https://github.com/PHPMailer/PHPMailer/blob/master/examples/sendmail.phps
		$mail = new PHPMailer( true );

		try {
			if ( $GLOBALS['wgCIFormsMailer'] === 'sendmail' ) {
				$mail->isSendmail();

			} else {
				$mail->isSMTP();
				$mail->Host = $GLOBALS['wgCIFormsSMTPHost'];
				$mail->SMTPAuth = true;
				$mail->Username = $GLOBALS['wgCIFormsSMTPUsername'];
				$mail->Password = $GLOBALS['wgCIFormsSMTPPassword'];
				$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				$mail->Port = $GLOBALS['wgCIFormsSMTPPort'];
			}

			$mail->IsHTML( true );

			// @see https://www.mediawiki.org/w/index.php?title=Topic:Xm1ys3dm8ijlegyb&topic_showPostId=xm1ys3dm8mhnmkwj#flow-post-xm1ys3dm8mhnmkwj
			$mail->CharSet = 'text/html; charset=UTF-8;';

			$from = ( !empty( $from->name ) ? $from->name . ' <' . $from->address . '>' : $from->address );

			$mail->setFrom( $from );
			foreach ( $to as $email ) {
				$mail->addAddress( $email );
			}
			$mail->Subject = $subject;
			$mail->msgHTML( $message_body );
			// $mail->addAttachment($attachment);

			$mail->AddStringAttachment( $attachment, $filename . '.pdf', 'base64', 'application/pdf' );
			$mail->send();
			return empty( $mail->ErrorInfo );
		} catch ( Exception ) {
			return false;
		}
	}

	/**
	 * @param array $form_result
	 * @param string &$username
	 * @return bool
	 */
	private function storeSubmission( $form_result, &$username ) {
		$user = RequestContext::getMain()->getUser();
		$username = $user->getName();
		$update_obj = [
			'title' => $form_result['form_values']['title'],
			'username' => $username,
			'page_id' => $form_result['form_values']['pageid'],
			'data' => json_encode( $form_result ),
			'created_at' => date( 'Y-m-d H:i:s' )
		];
		$dbr = \CIForms::getDB( DB_PRIMARY );
		if ( !$dbr->tableExists( 'CIForms_submissions' ) ) {
			return false;
		}
		$row_inserted = $dbr->insert(
			$this->sqlReplace( 'CIForms_submissions' ),
			$update_obj
		);
		$SubmissionGroups = self::mergeGlobal( 'data-access', $form_result['form_values'], $isLocal );
		if ( empty( $SubmissionGroups ) ) {
			$SubmissionGroups = self::mergeGlobal( 'submission-groups', $form_result['form_values'], $isLocal );
		}
		// store submissions groups
		if ( !empty( $SubmissionGroups ) ) {
			$groups = $SubmissionGroups;
			if ( ( $key = array_search( '*', $groups ) ) !== false ) {
				$groups[$key] = 'all';
			}
			if ( in_array( 'all', $groups ) ) {
				$groups = [ 'all' ];
			}
			if ( ( $key = array_search( 'sysop', $groups ) ) !== false ) {
				unset( $groups[$key] );
			}
			// a sysop can access all data, so we don't save usergroups related
			// to the submissions
			if ( !empty( $groups ) ) {

				if ( !$dbr->tableExists( 'CIForms_submissions_groups' ) ) {
					return false;
				}

				$latest_id = $dbr->selectField(
					$this->sqlReplace( 'CIForms_submissions' ),
					'id',
					[],
					__METHOD__,
					[ 'ORDER BY' => 'id DESC' ]
				);
				foreach ( $groups as $value ) {
					$row_inserted_ = $dbr->insert(
						$this->sqlReplace( 'CIForms_submissions_groups' ),
						[
							'submission_id' => $latest_id,
							'usergroup' => $value,
							'created_at' => date( 'Y-m-d H:i:s' )
						]
					);
				}
			}
		}
		return $row_inserted;
	}

	/**
	 * @param string $sql
	 * @return string
	 */
	private function sqlReplace( $sql ) {
		$dbr = \CIForms::getDB( DB_REPLICA );
		if ( $this->dbType == 'postgres' ) {
			$sql = str_replace( 'CIForms_', 'ciforms_', $sql );
		}
		return $sql;
	}

	/**
	 * @param string $name
	 * @param array $form_result
	 * @param bool &$isLocal
	 * @return string|array|null
	 */
	protected function mergeGlobal( $name, $form_result, &$isLocal ) {
		$types = [
			'wgCIFormsSubmissionGroups' => 'array',	// legacy
			'wgCIFormsDataAccess' => 'array',
			'wgCIFormsSubmitEmail' => 'array', // legacy
			'wgCIFormsEmailTo' => 'array',
			'wgCIFormsSuccessMessage' => 'string',
			'wgCIFormsErrorMessage' => 'string',
			'wgCIFormsSuccessPage' => 'string',
			'wgCIFormsErrorPage' => 'string',
		];
		$map = [ 'submission-groups', 'data-access', 'submit', 'email-to', 'success-message', 'error-message', 'success-page', 'error-page' ];
		$keys = array_keys( $types );
		$key = array_search( $name, $map );
		$globalName = $keys[$key];
		$globalMode = ( array_key_exists( $globalName . 'GlobalMode', $GLOBALS ) ? $GLOBALS[$globalName . 'GlobalMode'] : CIFORMS_VALUE_IF_NULL );
		$local = $form_result[$name];
		// avoid "SecurityCheck-XSS Calling method \CIFormsSubmit::exit() in \CIFormsSubmit::execute that outputs using tainted argument #2."
		$global = ( !empty( $GLOBALS[$globalName] ) ? htmlspecialchars( $GLOBALS[$globalName] ) : "" );
		$output = ( $types[$globalName] == 'array' ? [] : null );
		if ( $globalMode !== CIFORMS_VALUE_IF_NULL || empty( $local ) ) {
			$output = $global;
			if ( $types[$globalName] == 'array' && !is_array( $global ) ) {
				$output = preg_split( '/\s*,\s*/', $output, -1, PREG_SPLIT_NO_EMPTY );
			}
		}
		if ( $globalMode === CIFORMS_VALUE_OVERRIDE ) {
			return $output;
		}
		if ( $types[$globalName] == 'array' ) {
			$local = preg_split( '/\s*,\s*/', $local, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( empty( $local ) && $globalMode === CIFORMS_VALUE_IF_NULL ) {
			return $output;
		}
		if ( $globalMode === CIFORMS_VALUE_APPEND ) {
			if ( $types[$globalName] == 'array' ) {
				$output = array_unique( array_merge( $output, $local ) );
			// *** not clear if it does make sense
			} else {
				$isLocal = true;
				$output = $local . "\040" . $output;
			}
			return $output;
		}
		$isLocal = true;
		return $local;
	}

	/**
	 * @param array $form_result
	 * @param bool $row_inserted
	 * @param bool $dispatch
	 * @param bool $dispatched
	 * @param bool &$success
	 * @return string
	 */
	protected function exit_message( $form_result, $row_inserted, $dispatch, $dispatched, &$success ) {
		$errorMessage = self::mergeGlobal( 'error-message', $form_result['form_values'], $isLocal );
		$successMessage = self::mergeGlobal( 'success-message', $form_result['form_values'], $isLocal );
		if ( !$dispatch ) {
			if ( $row_inserted ) {
				$success = true;
				return ( $successMessage ?: $this->msg( 'ci-forms-data-saved' ) );
			} else {
				return ( $errorMessage ?: $this->msg( 'ci-forms-data-not-saved' ) );
			}
		}
		if ( $dispatched ) {
			$success = true;
			return ( $successMessage ?: $this->msg( 'ci-forms-dispatch-success' ) );
		}
		if ( $row_inserted ) {
			$success = true;
			return ( $successMessage ?: $this->msg( 'ci-forms-data-saved' ) );
		}
		// we don't use "ci-forms-dispatch-error-contact"
		// and "ci-forms-dispatch-error"anymore because we fallback
		// to $dispatch = false
		$formSubmit = self::mergeGlobal( 'email-to', $form_result['form_values'], $isLocal );
		// legacy
		if ( empty( $formSubmit ) ) {
			$formSubmit = self::mergeGlobal( 'submit', $form_result['form_values'], $isLocal );
		}
		if ( empty( $formSubmit ) ) {
			return ( $errorMessage ?: $this->msg( 'ci-forms-data-not-saved' ) );
		}
		return ( $errorMessage ?: $this->msg( 'ci-forms-data-not-saved-contact', implode( ', ', $formSubmit ) ) );
	}

	/**
	 * @param array $form_result
	 * @param string $username
	 * @param string $datetime
	 * @return string
	 */
	public function createPDF( $form_result, $username, $datetime ) {
		$css_path = __DIR__ . '/../../resources/style.css';
		$form_output_html = '';
		$form_output_html .= '<html><head>';
		$form_output_html .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>';
		// $form_output_html .= '<link rel="stylesheet" type="text/css" href="' . $css_url . '" />';
		$form_output_html .= '<style>';

		// @see https://www.mediawiki.org/w/index.php?title=Topic:Xuq3bozk9r5h72sp&topic_showPostId=xuq3bozk9v3jf6qx#flow-post-xuq3bozk9v3jf6qx
		$form_output_html .= '* { font-family: DejaVu Sans, sans-serif; }';

		$stylesheet = file_get_contents( $css_path );
		// ***ensure there aren't spaces between brackets otherwise
		// Dompdf will not work
		$stylesheet = preg_replace( '/\[\s*(.+?)\s*\]/', "[$1]", $stylesheet );
		// see here, Dompdf does not support bounding-box
		// https://github.com/dompdf/dompdf/issues/669
		$stylesheet = preg_replace( '/(?<!\-)width:\s*100%/', "max-width:100%", $stylesheet );
		$form_output_html .= $stylesheet;
		// https://github.com/dompdf/dompdf/issues/708
		$form_output_html .= '.ci_form ol li::before, .ci_form ul li::before { content: ""; }';
		$form_output_html .= '</style>';
		$form_output_html .= '<head><body>';
		$form_output_html .= $this->create_output(
			$form_result['form_values'],
			$form_result['sections']
		);
		// create table with
		// wiki name, page title, username, date time
		global $wgSitename;
		$form_output_html .= '<br /><br />';
		$form_output_html .= '<table border="1" style="width:100%;border: 1px solid #aaa;border-collapse:collapse;border-spacing:0;">';
		$form_output_html .= "<tr><td style=\"font-weight:bold\">wiki</td><td>$wgSitename</td></tr>";
		$form_output_html .= "<tr><td style=\"font-weight:bold\">page</td><td><a href=\"" . Title::newFromText( $form_result['form_values']['pagename'] )->getFullURL() . "\">{$form_result['form_values']['pagename']}</a></td></tr>";
		$form_output_html .= "<tr><td style=\"font-weight:bold\">username</td><td>$username</td></tr>";
		$form_output_html .= "<tr><td style=\"font-weight:bold\">date</td><td>$datetime</td></tr>";
		$form_output_html .= "</table>";
		$form_output_html .= '<br /><br /><br /><br /><br />';
		$form_output_html .= $this->msg( 'ci-forms-credits' );
		$form_output_html .= '</body></html>';
		// create pdf
		// https://github.com/dompdf/dompdf
		// instantiate and use the dompdf class
		$dompdf = new Dompdf();
		$dompdf->loadHtml( $form_output_html );
		// (Optional) Setup the paper size and orientation
		$dompdf->setPaper( 'A4' );
		// Render the HTML as PDF
		$dompdf->render();
		// Output the generated PDF to Browser
		// $dompdf->stream();
		$file = $dompdf->output();
		return $file;
	}

	/**
	 * @param OutputPage $out
	 * @param string $message
	 * @param null|array $form_values
	 * @param null|bool $success
	 */
	protected function exit( $out, $message, $form_values, $success ): void {
		if ( $success !== null ) {
			$errorPage = self::mergeGlobal( 'error-page', $form_values, $isLocal );
			$successPage = self::mergeGlobal( 'success-page', $form_values, $isLocal );
			$errorMessage = self::mergeGlobal( 'error-message', $form_values, $errorMessageIsLocal );
			$successMessage = self::mergeGlobal( 'success-message', $form_values, $successMessageIsLocal );
			if ( !$success && $errorPage && ( !$errorMessage || !$errorMessageIsLocal ) ) {
				$title = Title::newFromText( $errorPage );
				if ( $title && $title->isKnown() ) {
					header( 'Location: ' . $title->getLocalURL() );
				}
			}
			if ( $success && $successPage && ( !$successMessage || !$successMessageIsLocal ) ) {
				$title = Title::newFromText( $successPage );
				if ( $title && $title->isKnown() ) {
					header( 'Location: ' . $title->getLocalURL() );
				}
			}
		}
		if ( !empty( $form_values['pagename'] ) ) {
			$out->addWikiMsg(
				'ci-forms-manage-pager-return',
				$form_values['pagename']
			);
		}
		$html = '<p>' . $message . '</p>';
		$out->addHTML( $html );
	}

	/**
	 * @param array $post
	 * @return array|bool[]
	 */
	protected function check_captcha( $post ) {
		global $wgCIFormsGoogleRecaptchaSecret;
		if ( empty( $wgCIFormsGoogleRecaptchaSecret ) ) {
			return [ false, 'ci-forms-google-recaptcha-secret-not-set' ];
		}
		if ( empty( $post['g-recaptcha-response'] ) ) {
			return [ false, 'ci-forms-recaptcha-challenge-not-found' ];
		}
		$captcha = $post['g-recaptcha-response'];
		$response =
			file_get_contents( "https://www.google.com/recaptcha/api/siteverify?secret=" .
				$wgCIFormsGoogleRecaptchaSecret . "&response=" . $captcha . "&remoteip=" .
				$_SERVER['REMOTE_ADDR'] );
		// use json_decode to extract json response
		$response = json_decode( $response, true );
		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		if ( $response['success'] === false ) {
			// @phan-suppress-next-next-line PhanTypeArraySuspiciousNullable
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			return [ false, 'ci-forms-recaptcha-error', @$response['error-codes'][0] ];
		}
		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		if ( $response['success'] == true && $response['score'] <= 0.5 ) {
			return [ false, 'ci-forms-recaptcha-negative-score' ];
		}
		return [ true ];
	}

	/**
	 * @param array $post
	 * @return array
	 */
	protected function parseForm( $post ) {
		$props = [];
		$labels = [];
		// $values = [];
		$inputs = [];
		$form_values = [];
		$exclude = [ 'form', 'g-recaptcha-response', 'radio-for-required-checkboxes' ];
		foreach ( $post as $i => $value ) {
			$value = trim( $value );
			[ $section, $a, $b, $c, $d ] =
				// @phan-suppress-next-line PhanSuspiciousBinaryAddLists
				explode( '_', $i ) + [ null, null, null, null, null ];
			// this could be the "radio_for_required_checkboxes"
			if ( empty( $a ) ) {
				continue;
			}
			if ( in_array( $section, $exclude ) ) {
				if ( $section == 'form' ) {
					$form_values[$a] = $value;
				}
				continue;
			}
			if ( !array_key_exists( $section, $props ) ) {
				$props[$section] = [];
				$labels[$section] = [];
				// @phan-suppress-next-line PhanUndeclaredVariableDim
				$selected[$section] = [];
				$inputs[$section] = [];
			}
			switch ( $a ) {
				case 'section':
					$props[$section][$b] = $value;
					break;
				case 'items':
					if ( $c === 'label' ) {
						$labels[$section][$b] = $value;
					}
					// checkboxes
					if ( $c === 'selected' && $value !== '' ) {
						$selected[$section][$b] = true;
					}
					// radio, inputs unique name
					if ( $b === 'selected' && $value !== '' ) {
						$selected[$section][$value] = true;
					}
					if ( $c === 'input' ) {
						if ( !array_key_exists( $b, $inputs[$section] ) ) {
							$inputs[$section][$b] = [];
						}
						$inputs[$section][$b][$d] = $value;
					}
					break;
			}
		}
		$sections = [];
		foreach ( $props as $section => $value_ ) {
			$obj = array_merge( $value_, [ 'items' => [] ] );
			foreach ( $labels[$section] as $i => $row ) {
				$obj['items'][$i] = [
					'label' => $row,
					'selected' => ( !empty( $selected[$section][$i] ) ? $selected[$section][$i]
						: null ),
					'inputs' => ( !empty( $inputs[$section][$i] ) ? $inputs[$section][$i] : null ),
				];
			}
			$sections[] = $obj;
		}
		return [ 'form_values' => $form_values, 'sections' => $sections ];
	}

	/**
	 * @param string[] $form_values
	 * @param array[] $sections
	 * @return string
	 */
	protected function create_output( $form_values, $sections ) {
		$output = '<div class="ci_form pdf" style="max-width:none;background:none">';
		$output .= '<div class="ci_form_container">';
		if ( !empty( $form_values['title'] ) ) {
			$output .= '<div class="ci_form_title">';
			$output .= $form_values['title'];
			$output .= '</div>';
		}
		$output .= '<div class="ci_form_sections_container' .
			( count( $sections ) > 1 ? ' multiple_sections' : '' ) . '">';
		foreach ( $sections as $key => $section ) {
			$output .= '<div class="ci_form_section ' .
				htmlspecialchars( str_replace( ' ', '_', $section['type'] ) ) . '">';
			if ( !empty( $section['title'] ) ) {
				$output .= '<div class="ci_form_section_title">';
				$output .= $section['title'];
				$output .= '</div>';
			}
			switch ( $section['type'] ) {
				case 'inputs':
				// phpcs:ignore PSR2.ControlStructures.SwitchDeclaration.BodyOnNextLineCASE
				case 'inputs responsive':
					foreach ( $section['items'] as $value ) {
						$output .= '<div class="ci_form_section_inputs_row">';
						if ( $section['type'] == 'inputs responsive' ) {
							preg_match( "/^\s*([^\[\]]+)\s*(.+)\s*$/", $value['label'], $match );
							$value['label'] = $match[2];
							$output .= '<div class="ci_form_section_inputs_col-25">';
							$output .= $match[1];
							$output .= '</div><div class="ci_form_section_inputs_col-75">';
						}
						preg_match_all( '/([^\[\]]*)\[\s*([^\[\]]*)\s*\]\s*(\*)?/', $value['label'], $match_all );
						$inputs_per_row = count( $match_all[0] );
						$label_exists = !empty( implode( '', $match_all[1] ) );
						$i = 0;
						$output .= preg_replace_callback( '/([^\[\]]*)\[\s*([^\[\]]*)\s*\]\s*(\*)?/',
							static function ( $matches ) use ( $section, $value, &$i, $inputs_per_row, $label_exists ) {
								$replacement = '';
								$replacement .= '<div class="ci_form_section_inputs_inner_col" style="float:left;width:' . ( 100 / $inputs_per_row ) . '%">';
								[ $input_type, $placeholder, $input_options ] =
									CIForms::ci_form_parse_input_symbol( $matches[2] ) + [ null, null, null ];
								$required =
									( !empty( $matches[3] ) ? ' data-required="1" required' : '' );
								if ( $required && !empty( $placeholder ) ) {
									$placeholder .= ' *';
								}
								if ( $section['type'] != 'inputs responsive' ) {
									$label = trim( $matches[1] );
									if ( !empty( $label ) ) {
										$replacement .= '<label>' . $label .
											( $required && empty( $placeholder ) ? ' *' : '' ) . '</label>';
									// @see https://www.mediawiki.org/wiki/Topic:X0ywugj89ow4bzbm
									} elseif ( !empty( $placeholder ) ) {
										$replacement .= '<label>' . $placeholder . '</label>';
									} elseif ( $label_exists ) {
										// Zero-width space
										$replacement .= '<label>&#8203;</label>';
									}
								}
								$replacement .= '<span class="input">' .
									htmlspecialchars( $value['inputs'][$i] ) . '</span>';
								$replacement .= '</div>';
								$i++;
								return $replacement;
							}, $value['label'] ); // preg_replace_callback
						if ( $section['type'] == 'inputs responsive' ) {
							$output .= '</div>';
						}
						$output .= '</div>';
					}
					break;
				case 'multiple choice':
					$list_type_ordered = in_array( $section['list-style'], CIForms::$ordered_styles );
					// --list_style_type
					$output .= '<' . ( !$list_type_ordered ? 'ul' : 'ol' ) . ' class="ci_form_section_multiple_choice_list" style="list-style:' . $section['list-style'] . '">';
					foreach ( $section['items'] as $value ) {
						$label = $value['label'];
						$ii = -1;
						$output .= '<li>';
						// @see https://stackoverflow.com/questions/35200674/special-character-not-showing-in-html2pdf
						$output .= '<span style="font-family:DejaVu Sans, sans-serif">' .
							( $value['selected'] ? '&#9745;' : '&#9744;' ) . '</span>&nbsp;';
						$label =
							preg_replace_callback( '/\[([^\[\]]*)\]\s*\*?/',
								static function ( $matches ) use ( $value, &$ii ) {
									$ii++;
									return '<span class="input">' .
										htmlspecialchars( $value['inputs'][$ii] ) . '</span>';
								}, $label );
						$output .= $label;
						$output .= '</li>';
					}
					$output .= ( $list_type_ordered ? '</ol>' : '</ul>' );
					break;
				case 'cloze test':
					$output .= '<ol class="ci_form_section_cloze_test_list">';
					$list_type_ordered = in_array( $section['list-style'], CIForms::$ordered_styles );
					// --list_style_type
					$output .= '<' . ( !$list_type_ordered ? 'ul' : 'ol' ) . ' class="ci_form_section_cloze_test_list" style="list-style:' . $section['list-style'] . '">';
					foreach ( $section['items'] as $value ) {
						$label = trim( $value['label'] );
						$example = ( $label[0] == '*' );
						if ( $example ) {
							$label = trim( substr( $label, 1 ) );
							// simply ignore the example line since
							// the numeration isn't handled correctly by
							// Dompdf using css counter-increment
							continue;
						}
						$output .= '<li class="ci_form_section_cloze_test_list_question' .
							( $example ? '_example' : '' ) . '">';
						$i = 0;
						$output .= preg_replace_callback( '/\[\s*([^\[\]]*)\s*\]\s*\*?/',
							static function ( $matches ) use ( &$i, $value ) {
									$a = $b = null;
								if ( !empty( $matches[1] ) ) {
									[ $a, $b ] = preg_split( "/\s*=\s*/", $matches[1] ) + [ null, null ];
								}
								$replacement_inner = '';
								if ( $a || $b ) {
									$replacement_inner .= '<span class="ci_form_section_cloze_test_list_question_answered">' .
										( $b ?: $a ) .
										'</span> ';
								} else {
									// '_value' is appended for easy validation
									$replacement_inner .= '<span class="input">' .
										htmlspecialchars( $value['inputs'][$i] ) . '</span> ';
								}
								$i++;
								return $replacement_inner;
							}, $label );
						$output .= '</li>';
					}
					$output .= '</ol>';
					break;
			}
			$output .= '</div>';
		}
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';
		return $output;
	}
}
