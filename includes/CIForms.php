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

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CIForms {
	/** @var array */
	public static $ordered_styles = [
		'decimal',
		'decimal-leading-zero',
		'lower-roman',
		'upper-roman',
		'lower-greek',
		'lower-latin',
		'upper-latin',
		'armenian',
		'georgian',
		'lower-alpha',
		'upper-alpha',
	];

	/**
	 * @param array $credits
	 */
	public static function initExtension( $credits = [] ) {
		if ( !defined( 'CIFORMS_VALUE_IF_NULL' ) ) {
			define( 'CIFORMS_VALUE_IF_NULL', 0 );
		}
		if ( !defined( 'CIFORMS_VALUE_OVERRIDE' ) ) {
			define( 'CIFORMS_VALUE_OVERRIDE', 1 );
		}
		if ( !defined( 'CIFORMS_VALUE_APPEND' ) ) {
			define( 'CIFORMS_VALUE_APPEND', 2 );
		}
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki|MediaWiki\Actions\ActionEntryPoint $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( &$title, $unused, $output, $user, $request, $mediaWiki ) {
		self::initExtension();
	}

	/**
	 * Register any render callbacks with the parser
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'ci_form', [ self::class, 'ci_form' ] );
		$parser->setFunctionHook( 'ci_form_section', [ self::class, 'ci_form_section' ] );
	}

	/**
	 * @param OutputPage $outputPage
	 * @param ParserOutput $parserOutput
	 */
	public static function onOutputPageParserOutput( OutputPage $outputPage, ParserOutput $parserOutput ) {
		// @todo, use "messages" in extension.json and mw.msg() client side
		$outputPage->addJsConfigVars( [
			'ci-forms-validation-msg1' => wfMessage( 'ci-forms-validation-msg1' )->text(),
			'ci-forms-validation-msg2' => wfMessage( 'ci-forms-validation-msg2' )->text(),
			'ci-forms-validation-msg3' => wfMessage( 'ci-forms-validation-msg3' )->text(),
		] );

		$title = $outputPage->getTitle();
		$categories = $title->getParentCategories();
		$funcName = version_compare( MW_VERSION, '1.38', '<' ) ? 'getFlag' : 'getOutputFlag';

		if ( $parserOutput->getExtensionData( 'ciform' ) !== null
			// back-compatibility
			// @phan-suppress-next-line PhanUndeclaredMethod -- *** false positive ?
			|| $parserOutput->$funcName( 'ciform' )
			|| array_key_exists( 'Category:Pages_with_forms', $categories ) ) {

			global $wgCIFormsGoogleRecaptchaSiteKey;
			global $wgResourceBasePath;
			$outputPage->addModules( 'ext.CIForms.validation' );
			if ( self::isCaptchaEnabled() ) {
				$outputPage->addJsConfigVars( [
					'ci_forms_google_recaptcha_site_key' => $wgCIFormsGoogleRecaptchaSiteKey
				] );
				$outputPage->addHeadItem( 'captcha_style',
					'<style>.grecaptcha-badge { visibility: hidden; display: none; }</style>' );
			}
			$items = [
				[ 'stylesheet', $wgResourceBasePath . '/extensions/CIForms/resources/style.css' ],
			];
			foreach ( $items as $key => $val ) {
				[ $type, $url ] = $val;
				switch ( $type ) {
					case 'stylesheet':
						$item = '<link rel="stylesheet" href="' . $url . '" />';
						break;
					case 'script':
						$item = '<script src="' . $url . '"></script>';
						break;
				}
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
				$outputPage->addHeadItem( 'CI_head_item_' . $key, $item );
			}
		}
	}

	/**
	 * @param DatabaseUpdater|null $updater
	 */
	public static function onLoadExtensionSchemaUpdates( ?DatabaseUpdater $updater = null ) {
		$base = __DIR__;
		$dbType = $updater->getDB()->getType();
		$array = [
			[
				'table' => 'CIForms_submissions',
				'filename' => '../' . $dbType . '/CIForms_submissions.sql'
			],
			[
				'table' => 'CIForms_submissions_groups',
				'filename' => '../' . $dbType . '/CIForms_submissions_groups.sql'
			],
		];
		foreach ( $array as $value ) {
			if ( file_exists( $base . '/' . $value['filename'] ) ) {
				$updater->addExtensionUpdate(
					[
						'addTable', $value['table'],
						$base . '/' . $value['filename'], true
					]
				);
			}
		}

		$updater->addExtensionField(
			( $dbType === 'mysql' ? 'CIForms_submissions' : 'ciforms_submissions' ),
			'username',
			"$base/../$dbType/CIForms_submissions_username.sql"
		);
	}

	/**
	 * @return bool
	 */
	public static function isCaptchaEnabled() {
		global $wgCIFormsGoogleRecaptchaSiteKey;
		global $wgCIFormsGoogleRecaptchaSecret;
		return ( !empty( $wgCIFormsGoogleRecaptchaSiteKey ) && !empty( $wgCIFormsGoogleRecaptchaSecret ) );
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function ci_form( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();
		$parserOutput->setExtensionData( 'ciform', true );
		$title = $parser->getTitle();

		$named_parameters = [
			'submit' => null, // legacy
			'email to' => null,
			'title' => null,
			'submission groups' => null, // legacy
			'data access' => null,
			'success message' => null,
			'error message' => null,
			'success page' => null,
			'error page' => null,
			'paging' => null, // true, or 1,1,1,1,1, or 2,2,1
			'submit text' => null,
			'navigation next' => null,
			'navigation back' => null,
			'css class' => '',
		]; // email to which submit
		$parser->addTrackingCategory( 'ci-form' );
		$set_named_parameters = [];
		$body = self::parse_function_arguments( $argv, $named_parameters, $set_named_parameters );
		$subsections = [];
		foreach ( $body as $key => $value ) {
			// https://www.mediawiki.org/wiki/Strip_marker
			// @todo add some logic to fix a missing pipe
			if ( preg_match( '/^(\x7F\'"`UNIQ.+?QINU`"\'\x7F){1}(\s+\x7F\'"`UNIQ.+?QINU`"\'\x7F)*$/',
				$value ) ) {
				// *** handle missing pipes between nested sections
				preg_match_all( '/\x7F\'"`UNIQ.+?QINU`"\'\x7F/', $value, $match );
				$subsections = array_merge( $subsections, $match[0] );
				unset( $body[$key] );
			}
		}
		$paging = ( count( $subsections ) && !empty( $named_parameters['paging'] ) && $named_parameters['paging'] !== 'false' );
		$output = '';

		$url = SpecialPage::getTitleFor( 'CIFormsSubmit' )->getLocalURL();
		// https://www.accessibility-developer-guide.com/examples/forms/required/
		$output .= '<svg style="display:none" id="definition" version="1.1" xmlns="http://www.w3.org/2000/svg"><defs><symbol id="required" viewbox="0 0 128 128"><g><path d="M110.1,16.4L75.8,56.8l0.3,1l50.6-10.2v32.2l-50.9-8.9l-0.3,1l34.7,39.1l-28.3,16.5L63.7,78.2L63,78.5   l-18.5,49L17.2,111l34.1-39.8v-0.6l-50,9.2V47.6l49.3,9.9l0.3-0.6L17.2,16.7L45.5,0.5l17.8,48.7H64L82.1,0.5L110.1,16.4z"></path></g></symbol></defs></svg>';

		$output .= '<form class="ci_form' . ( !empty( $named_parameters['css class'] ) ? " " .
				htmlspecialchars( str_replace( [ '"', "'" ], '', $named_parameters['css class'] ) ) : '' ) . '" action="' . $url .
			'" method="post">';
		$output .= '<div class="ci_form_container">';
		// allow wiki-text and html in titles
		if ( !empty( $named_parameters['title'] ) ) {
			$named_parameters['title'] =
				self::replace_wikitext_and_html( $title, $named_parameters['title'] );
		}
		if ( !empty( $named_parameters['title'] ) ) {
			$output .= '<div class="ci_form_title">';
			$output .= self::replace_wikitext_and_html( $title, $named_parameters['title'] );
			$output .= '</div>';
		}
		$output .= '<div class="ci_form_sections_container' .
			( count( $subsections ) ? ' multiple_sections' : '' ) . '">';
		if ( count( $body ) ) {
			$output .= self::ci_form_section_process( $title, $body );
		}
		if ( count( $subsections ) ) {
			if ( $paging ) {
				// we cannot set the visibility in the section's container
				// itself, because the nested parser function is rendered
				// independently before the container
				// group according to paging
				// eg. 5 sections
				// true = 1, 1, 1, 1, 1
				// 2, 2, 1
				// 2, 2 = 2, 2, 1
				// 2, 3
				// 2, 4 = 2, 3
				// 6, 1 = 5
				if ( $named_parameters['paging'] === 'true' ) {
					$split = array_fill( 0, count( $subsections ), 1 );
				} else {
					$split = preg_split( "/\s*,\s*/", $named_parameters['paging'], -1, PREG_SPLIT_NO_EMPTY );

					if ( count( $split ) == 1 ) {
						$split = array_fill( 0, ceil( count( $subsections ) / (int)$split[0] ), (int)$split[0] );
					}
				}
				$n = 0;
				foreach ( $split as $i => $value ) {
					$output .= '<div class="ci_form_section_display_' . $i . '" style="display:' . ( $i > 0 ? 'none' : 'block' ) . '">' . implode( array_slice( $subsections, $n, $value ) ) . '</div>';
					$n += $value;
				}
			} else {
				$output .= implode( $subsections );
			}
		}
		$output .= '</div>';
		$output .= '<div class="ci_form_section_submit">';
		$hidden_fields = [ 'title', 'submission groups', 'data access', 'submit', 'email to', 'success message', 'error message', 'paging', 'success page', 'error page' ];
		foreach ( $hidden_fields as $value ) {
			$output .= self::hidden_input( 'form_' . str_replace( ' ', '-', $value ), $named_parameters[$value] );
		}
		if ( self::isCaptchaEnabled() ) {
			$output .= '<input type="hidden" name="g-recaptcha-response">';
		}
		$output .= self::hidden_input( 'form_pagename', $title->getText() );
		$output .= self::hidden_input( 'form_pageid', $title->getArticleID() );
		if ( !$paging ) {
			$output .= '<input class="ci_form_input_submit" type="submit" value="' . ( !empty( $named_parameters['submit text'] ) ? htmlspecialchars( $named_parameters['submit text'] ) : wfMessage( 'ci-forms-submit' )->text() ) . '">';
		} else {
			$output .= '<div style="text-align:right">';
			$output .= '<button style="display:none" type="button" class="ci_form_input_navigation_back">' . ( !empty( $named_parameters['navigation back'] ) ? htmlspecialchars( $named_parameters['navigation back'] ) : wfMessage( 'ci-forms-navigation-back' )->text() ) . '</button>';
			$output .= '<button class="ci_form_input_navigation_next">' . ( !empty( $named_parameters['navigation next'] ) ? htmlspecialchars( $named_parameters['navigation next'] ) : wfMessage( 'ci-forms-navigation-next' )->text() ) . '</button>';
			$output .= '<input style="display:none" class="ci_form_input_submit" type="submit" value="' . ( !empty( $named_parameters['submit text'] ) ? htmlspecialchars( $named_parameters['submit text'] ) : wfMessage( 'ci-forms-submit' )->text() ) . '">';
			$output .= '</div>';
		}
		$output .= '</div>';
		$output .= '</div>';
		$output .= '<div class="ci_form_section_captcha">';
		if ( self::isCaptchaEnabled() ) {
			$output .= 'form protected using <a target="_blank" style="color:silver;text-decoration:" href="https://www.google.com/recaptcha/about/">Google recaptcha</a>';
		}
		$output .= '</div>';
		$output .= '</form>';
		return [
			$output,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/**
	 * @param array $argv
	 * @param array &$named_parameters
	 * @param array &$set_named_parameters
	 * @return array
	 */
	protected static function parse_function_arguments(
		$argv,
		&$named_parameters,
		&$set_named_parameters
	) {
		$body = [];
		// $set_named_parameters = [];
		$unique_id = uniqid();
		foreach ( $argv as $value ) {
			$value = trim( $value );
			$value = preg_replace( '/\040+/', ' ', $value );
			if ( empty( $value ) ) {
				continue;
			}
			// square brackets may contain an equal symbol
			// so we temporarily remove it
			// $value_ = preg_replace('/\[\s*(.+?)\s*\]\s*\*?/','',$value);
			// replace html and square brackets with some identifier
			$replacements = [];
			$value_ =
				preg_replace_callback( '/(<[^<>]+>)|(\[[^\[\]]+\])/',
					static function ( $matches ) use ( &$replacements, $unique_id ) {
						if ( $matches[1] ) {
							$replacements[] = '<html>' . $matches[0] . '</html>';
						} else {
							$replacements[] = $matches[0];
						}
						return $unique_id;
					}, $value );
			if ( strpos( $value_, '=' ) !== false ) {
				[ $parameter_key, $parameter_value ] = explode( '=', $value_, 2 );
				$parameter_key = trim( str_replace( [ '_', '-' ], ' ', $parameter_key ) );
				if ( array_key_exists( $parameter_key, $named_parameters ) ) {
					$parameter_value =
						preg_replace_callback( '/' . $unique_id . '/',
							static function ( $matches ) use ( &$replacements, $unique_id ) {
								return array_shift( $replacements );
							},
							$parameter_value );
					$named_parameters[$parameter_key] = trim( $parameter_value );
					$set_named_parameters[] = $parameter_key;
					continue;
				}
			}
			$body[] = $value;
		}
		return $body;
	}

	/**
	 * @param Title $title
	 * @param string $value
	 * @return array|string|string[]|null
	 */
	protected static function replace_wikitext_and_html( $title, $value ) {
		$context = new RequestContext();
		// see below, parseAsContent requires a title
		// which is not set when executed offline
		$context->setTitle( $title );
		$out_ = $context->getOutput();

		$unique_id = uniqid();
		$replacements = [];
		$value =
			preg_replace_callback( '/<[^<>]+>/',
				static function ( $matches ) use ( &$replacements, $unique_id ) {
					$replacements[] = $matches[0];
					return $unique_id;
				}, $value );
		$value = Parser::stripOuterParagraph( $out_->parseAsContent( $value ) );
		$value =
			preg_replace_callback( '/' . $unique_id . '/',
				static function ( $matches ) use ( &$replacements, $unique_id ) {
					return array_shift( $replacements );
				}, $value );
		return $value;
	}

	/**
	 * @param string $name
	 * @param string|int|null $value
	 * @return string $value
	 */
	protected static function hidden_input( $name, $value ) {
		return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars( (string)$value ) . '">';
	}

	/**
	 * @param Title $title
	 * @param array|string[] $argv
	 * @return string
	 */
	protected static function ci_form_section_process( $title, $argv ) {
		$output = '';
		// default values
		$named_parameters = [
			'type' => 'inputs',
			// 'inputs', 'inputs resposive', 'multiple choice', 'cloze', 'cloze-test'
			'title' => null,
			'list type' => 'none',
			// 'unordered', 'letters', 'numbers' + standard values
			'max answers' => 1,
			// number or percent, for multiple choice questions if max answers > 1, default 1/2 +1
			'min answers' => null,
			'suggestions' => null,
			'css class' => '',
			// if multiple choice
		];
		$set_named_parameters = [];
		$body = self::parse_function_arguments( $argv, $named_parameters, $set_named_parameters );
		// alias
		if ( $named_parameters['type'] == 'cloze' ) {
			$named_parameters['type'] = 'cloze test';
		}
		// alias
		if ( $named_parameters['type'] == 'input' ) {
			$named_parameters['type'] = 'inputs';
		}
		// cloze test list type default value
		if ( !in_array( 'list type', $set_named_parameters ) &&
			$named_parameters['type'] == 'cloze test' ) {
			$named_parameters['list type'] = 'ordered';
		}
		$unique_id = uniqid();
		$output .= '<div class="ci_form_section ' . htmlspecialchars( str_replace( ' ', '_', $named_parameters['type'] ) ) .
			( !empty( $named_parameters['css class'] ) ? " " . htmlspecialchars( str_replace( [ '"', "'" ], '', $named_parameters['css class'] ) ) : '' ) .
			'" data-id="' .	$unique_id . '">';

		switch ( $named_parameters['type'] ) {
			case 'cloze test':
			case 'multiple choice':
				if ( in_array( $named_parameters['list type'], self::$ordered_styles ) ) {
					$list_style = $named_parameters['list type'];
				} else {
					switch ( $named_parameters['list type'] ) {
						case 'letters':
							$list_style = 'upper-latin';
							break;
						case 'ordered':
						case 'numbers':
							$list_style = 'decimal';
							break;
						case 'unordered':
							$list_style = 'disc';
							break;
						default:
							$list_style = 'none';
					}
				}
				$output .= self::hidden_input( $unique_id . '_section_list-style', $list_style );
				if ( $named_parameters['type'] == 'multiple choice' ) {
					$output .= self::hidden_input( $unique_id . '_section_multiple-choice-max-answers', $named_parameters['max answers'] );
				}
				if ( (int)$named_parameters['max answers'] > 1 || $named_parameters['type'] == 'cloze test' ) {
					$output .= self::hidden_input( $unique_id . '_section_multiple-choice-min-answers', $named_parameters['min answers'] );
				}
				break;
			case 'inputs':
			case 'inputs responsive':
				break;
		}
		// allow wiki-text and html in titles
		if ( !empty( $named_parameters['title'] ) ) {
			$named_parameters['title'] =
				self::replace_wikitext_and_html( $title, $named_parameters['title'] );
		}
		$output .= self::hidden_input( $unique_id . '_section_type', $named_parameters['type'] );
		$output .= self::hidden_input( $unique_id . '_section_title', $named_parameters['title'] );

		// do some "look-ahead" processing
		switch ( $named_parameters['type'] ) {
			case 'inputs':
			case 'inputs responsive':
				$parsed = [];
				$placeholders = 0;
				$labels = 0;
				foreach ( $body as $value ) {
					// parsing error, nested square brackets
					// @todo, add different error-cases
					if ( preg_match( '/\[ [^\[\]]* \[ /x', $value ) ) {
						$parsed[] = null;
						continue;
					}

					$main_label = null;
					if ( $named_parameters['type'] == 'inputs responsive' ) {
						preg_match( "/^\s*([^\[\]]+)\s*(.+)\s*$/", $value, $match );
						if ( $match ) {
							$value = $match[2];
							$main_label = trim( $match[1] );
						}
					}

					$inputs = [];
					$has_required = false;
					$has_labels = false;
					preg_match_all( '/([^\[\]]*)\[\s*([^\[\]]*)\s*\]\s*(\*)?/', $value, $matches, PREG_SET_ORDER );

					foreach ( $matches as $match ) {
						$label = trim( $match[1] );
						$required = !empty( $match[3] );
						[ $input_type, $placeholder, $input_options ] =
							self::ci_form_parse_input_symbol( $match[2] ) + [ null, "", null ];

						$inputs[] = [
							'required' => $required,
							'label' => $label,
							'input_type' => $input_type,
							'placeholder' => $placeholder,
							'input_options' => $input_options
						];

						if ( !empty( $placeholder ) ) {
							$placeholders++;
						}
						if ( !empty( $label ) ) {
							$labels++;
							$has_labels = true;
						}

						if ( $required ) {
							$has_required = true;
						}
					}

					$parsed[] = [
						'main_label' => $main_label,
						'value' => $value,
						'inputs' => $inputs,
						'has_required' => $has_required,
						'has_labels' => $has_labels,
					];
				}

				$required_html = '<span class="ci_form_required_symbol" aria-hidden="true">&nbsp;*</span>';

				// add required asterisk here if the section
				// has only one field and label and placeholder are empty
				$required_rendered = false;
				if ( !empty( $named_parameters['title'] ) ) {
					$output .= '<div class="ci_form_section_title">' . $named_parameters['title'];

					if ( count( $body ) === 1 && $parsed[0] && $parsed[0]['has_required'] && $placeholders === 0 && $labels === 0 ) {
						$required_rendered = true;
						$output .= $required_html;
					}

					$output .= '</div>';
				}

				break;
			default:
				if ( !empty( $named_parameters['title'] ) ) {
					$output .= '<div class="ci_form_section_title">' . $named_parameters['title'] . '</div>';
				}
		}

		switch ( $named_parameters['type'] ) {
			case 'inputs':
			case 'inputs responsive':
				foreach ( $body as $key => $value ) {
					// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
					if ( $parsed[$key] === null ) {
						$output .= '<div class="ci_form_section_inputs_row_error">';
						$output .= wfMessage( 'ci-forms-parsing-error' )->text();
						$output .= '</div>';
						continue;
					}
					$parsed_row = $parsed[$key];

					$output .= '<div class="ci_form_section_inputs_row">';
					$output .= self::hidden_input( $unique_id . '_items_' . $key . '_label', $value );

					if ( $named_parameters['type'] == 'inputs responsive' ) {
						if ( $parsed_row['main_label'] ) {
							$output .= '<div class="ci_form_section_inputs_col-25">';
							$output .= $parsed_row['main_label'];
						} else {
							$output .= '<div class="ci_form_section_inputs_col">';
						}

						if ( $parsed_row['has_required'] ) {
							$output .= $required_html;
						}
						$output .= '</div><div class="ci_form_section_inputs_col-75">';
					}

					$inputs_per_row = count( $parsed_row['inputs'] );
					$i = 0;
					$output .= preg_replace_callback( '/([^\[\]]*)\[\s*([^\[\]]*)\s*\]\s*(\*)?/',
						static function ( $matches ) use ( $named_parameters, &$i, $key, $parsed_row, $unique_id, $inputs_per_row, $placeholders, $labels, $required_html, $required_rendered ) {
							// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
							$input = $parsed_row['inputs'][$i];
							$replacement = '';
							$replacement .= '<div class="ci_form_section_inputs_inner_col" style="float:left;width:' . ( 100 / $inputs_per_row ) . '%">';

							$required = ( $input['required'] ? ' data-required="1"' : '' );
							$label = $input['label'];
							$placeholder = $input['placeholder'];

							if ( $named_parameters['type'] != 'inputs responsive' ) {
								if ( empty( $label ) && $input['required'] && $labels > $placeholders && !$required_rendered ) {
									$required_rendered = true;
									$replacement .= '<label>(' . wfMessage( 'ci-forms-field-required' )->text() . $required_html . ')</label>';
								} elseif ( !empty( $label ) ) {
								// if ( !empty( $label ) ) {
									$replacement .= '<label>' . $label . ( $required ? $required_html : '' ) . '</label>';
								} elseif ( $parsed_row['has_labels'] ) {
									// Zero-width space
									$replacement .= '<label>&#8203;</label>';
								}
							}

							if ( $named_parameters['type'] != 'inputs responsive' || $inputs_per_row > 1 ) {
								if ( empty( $label ) && $input['required'] && !$required_rendered ) {
									$placeholder .= ( !empty( $placeholder ) ? '' : wfMessage( 'ci-forms-field-required' )->text() ) . ' *';
								}
							}

							$input_type = $input['input_type'];
							$input_options = $input['input_options'];

							$replacement .= '<div class="ci_form_section_inputs_inner_col_input_container" style="width:' . ( $named_parameters['type'] == 'inputs' || $inputs_per_row > 1 ? '100%' : 'auto' ) . '">';
							switch ( $input_type ) {
								case 'textarea':
									// '_value' is appended for easy validation
									$replacement .= '<textarea rows="4" name="' . $unique_id .
										'_items_' . $key . '_input_' . $i . '_value"' .
										( $input_options && is_numeric( $input_options ) ? ' maxlength="' . $input_options . '"' : '' ) .
										( !empty( $placeholder ) ? ' placeholder="' .
											htmlspecialchars( $placeholder ) . '"' : '' ) .
										$required . '></textarea>' .
										( $input_options && is_numeric( $input_options ) ? '<span class="ci_form_section_inputs_textarea_maxlength">0/' . $input_options . ' ' . wfMessage( 'ci-forms-textarea-maxlength-characters' )->text() . '</span>' : '' );
									break;
								case 'select':
									// *** this could be replaced with any symbol
									// not allowed in the input descriptor
									// $placeholder can be: a,b, c, or a:x,b:y, c:z, or: a:x\, a, b:y (comma
									// can be escaped
									$id_tmp = uniqid();
									$select_options = str_replace( '\\,', $id_tmp, $input_options );
									$select_options = preg_split( "/\s*,\s*/", $select_options, -1, PREG_SPLIT_NO_EMPTY );
									$replacement .= '<select name="' . $unique_id . '_items_' . $key .
										'_input_' . $i . '_value" type="' . $input_type . '"' .
										$required . '>';
									if ( !empty( $placeholder ) ) {
										$replacement .= '<option value="" disabled selected>' . $placeholder . '</option>';
									}
									$replacement .= implode( array_map(
										static function ( $val ) use ( $id_tmp ) {
											$val = str_replace( $id_tmp, ',', $val );
											// replace with Zero-width space
											// to ensure select2 renders the character properly
											if ( empty( $val ) ) {
												$val = ':&#8203;';
											}
											if ( strpos( $val, ':' ) === false ) {
												return '<option>' . $val . '</option>';
											}
											[ $value, $label ] = preg_split( "/\s*:\s*/", $val, -1, PREG_SPLIT_NO_EMPTY );
											return '<option value="' . htmlspecialchars( $value ) . '">' . $label . '</option>';
										}, $select_options ) );
									$replacement .= '</select>';
									break;
								default:
								case 'text':
								case 'email':
									// '_value' is appended for easy validation
									$replacement .= '<input name="' . $unique_id . '_items_' . $key .
										'_input_' . $i . '_value" type="' . $input_type . '"' .
										( $placeholder ? ' placeholder="' .
											htmlspecialchars( $placeholder ) . '"' : '' ) .
										$required . '/>';
									break;
							}
							// if ( $required && !$label && !$required_in_placeholder && ( $named_parameters['type'] != 'inputs responsive' || $inputs_per_row > 1 ) ) {
							// 	$replacement .= '<span class="ci_form_required_icon" aria-hidden="true"><svg class="icon" focusable="false"><use xlink:href="#required"></use></svg></span>';
							// }

							$replacement .= '</div>';
							$replacement .= '</div>';
							$i++;
							return $replacement;
						}, $value ); // preg_replace_callback
					if ( $named_parameters['type'] == 'inputs responsive' ) {
						$output .= '</div>';
					}
					$output .= '</div>';
				}
				break;
			case 'multiple choice':
				$list_type_ordered = in_array( $list_style, self::$ordered_styles );
				// https://stackoverflow.com/questions/23699128/how-can-i-reset-a-css-counter-to-the-start-attribute-of-the-given-list
				$output .= '<' . ( !$list_type_ordered ? 'ul' : 'ol' ) . ' class="ci_form_section_multiple_choice_list" style="--list_style_type:' . $list_style . '">';
				$n = 0;
				// native validation, see the following:
				// https://stackoverflow.com/questions/8287779/how-to-use-the-required-attribute-with-a-radio-input-field
				// https://stackoverflow.com/questions/6218494/using-the-html5-required-attribute-for-a-group-of-checkboxes
				$output .= ( $named_parameters['max answers'] > 1
					? '<input class="radio_for_required_checkboxes" type="radio" name="' .
					uniqid() . '" data-required="1" />' : '' );
				foreach ( $body as $key => $value ) {
					$output .= '<li>';
					$output .= self::hidden_input( $unique_id . '_items_' . $n . '_label', $value );
					// if it's a radio, the input name shall be the same for all inputs
					$output .= '<input name="' . $unique_id . '_items_' .
						( $named_parameters['max answers'] > 1 ? $n . '_' : '' ) .
						'selected" type="' .
						( $named_parameters['max answers'] == 1 ? 'radio' : 'checkbox' ) .
						'" value="' . $n . '"' .
						( $named_parameters['max answers'] == 1 ? ' data-required="1"' : '' ) . ' />';
					$i = 0;
					$output .= preg_replace_callback( '/\[([^\[\]]*)\]/',
						static function ( $matches ) use ( &$i, $n, $unique_id ) {
							$replacement =
								'<input name="' . $unique_id . '_items_' . $n . '_input_' . $i .
								'" type="text" data-required="1" />';
							$i++;
							return $replacement;
						}, $value ); // preg_replace_callback
					$output .= '</li>';
					$n++;
				}
				$output .= ( $list_type_ordered ? '</ol>' : '</ul>' );
				break;
			case 'cloze test':
				$suggestions = [];
				if ( !empty( $named_parameters['suggestions'] ) ) {
					$suggestions = preg_split( "/\s*,\s*/", $named_parameters['suggestions'], -1, PREG_SPLIT_NO_EMPTY );
				}
				$items = [];
				$answers = [];
				foreach ( $body as $key => $value ) {
					$example = false;
					$value = trim( $value );
					$value = preg_replace( '/\s+/', ' ', $value );
					// @todo in a cloze test the asterisk used to mark an example is redundant
					if ( $value[0] == '*' ) {
						$example = true;
						$value = trim( substr( $value, 1 ) );
					}
					preg_match_all( '/\[\s*([^\[\]]*)\s*\]/', $value, $matches );
					$inputs = [];
					if ( !empty( $matches[0] ) ) {
						foreach ( $matches[0] as $i => $match ) {
							$a = $b = null;
							if ( $matches[1][$i] ) {
								// the suggestions could be "transformed" regard
								// a possible answer, for instance:
								// suggestions: "to be, to do, to make"
								// example answer in past perfect
								// I [to be=was] proud to win ...
								[ $a, $b ] = preg_split( "/\s*=\s*/", $matches[1][$i], -1, PREG_SPLIT_NO_EMPTY ) + [ null, null ];
							}
							$found_suggestion = preg_grep( '/^' . preg_quote( (string)$a ) . '$/i', $suggestions );
							if ( count( $found_suggestion ) ) {
								$answers[] = array_shift( $found_suggestion );
							}
							$inputs[] = [ $a, $b ];
						}
					}
					$items[] = [ $value, $example, $inputs ];
				}
				shuffle( $suggestions );
				$output .= self::hidden_input( $unique_id .	'_section_cloze-test-suggestions', implode( ',', $suggestions ) );
				$output .= self::hidden_input( $unique_id .	'_section_cloze-test-answers', implode( ',', $answers ) );
				// suggestions framed
				if ( !empty( $suggestions ) ) {
					$output .= '<div class="ci_form_section_cloze_test_suggestions">';
					foreach ( $suggestions as $word ) {
						$output .= '<span class="ci_form_section_cloze_test_suggestions_word' .
							( in_array( $word, $answers ) ? '_answered' : '' ) . '">';
						$output .= $word;
						$output .= '</span>';
						if ( in_array( $word, $answers ) ) {
							$key = array_search( $word, $answers );
							unset( $answers[$key] );
						}
					}
					$output .= '</div>';
				}
				$list_type_ordered = in_array( $list_style, self::$ordered_styles );

				// https://www.mediawiki.org/wiki/Topic:Yh239sott8bbkc0e
				$id_ = rand( 0, getrandmax() );
				$output .= '<style>';
				$output .= '.ci_form #ci_form_list_' . $id_ . ' li::before {';
				// https://stackoverflow.com/questions/23699128/how-can-i-reset-a-css-counter-to-the-start-attribute-of-the-given-list
				// *** the use of the css variable isn't anymore necessary
				$output .= '	content: counter( li, ' . $list_style . ' );';
				$output .= '}';
				$output .= '</style>';
				$output .= '<' . ( !$list_type_ordered ? 'ul' : 'ol' ) . ' id="ci_form_list_' . $id_ . '" class="ci_form_section_cloze_test_list">';
				$n = 0;
				foreach ( $items as $value ) {
					[ $label, $example, $inputs ] = $value;
					$output .= '<li class="ci_form_section_cloze_test_list_question' .
						( $example ? '_example' : '' ) . '">';
					$output .= self::hidden_input( $unique_id .	'_items_' . $n . '_label', ( $example ? '* ' : '' ) . $label );
					$i = 0;
					$label =
						preg_replace_callback( '/\[([^\[\]]*)\]/',
							static function ( $matches ) use ( &$i, &$inputs, $example, $n, $unique_id ) {
								[ $a, $b ] = array_shift( $inputs );
								$replacement = '';
								if ( $a || $b ) {
									$replacement .= '<span class="ci_form_section_cloze_test_list_question_answered">' .
										( $b ?: $a ) .
										'</span>';
								} else {
									// '_value' is appended for easy validation
									$replacement .= '<input name="' . $unique_id . '_items_' . $n .
										'_input_' . $i . '_value" type="text" />';
								}
								$i++;
								return $replacement;
							}, $label ); // preg_replace_callback
					$output .= $label;
					$output .= '</li>';
					$n++;
				}
				$output .= ( $list_type_ordered ? '</ol>' : '</ul>' );
				break;
		}
		$output .= '</div>';
		return $output;
	}

	/**
	 * @param string $value
	 * @return array
	 */
	public static function ci_form_parse_input_symbol( $value ) {
		if ( empty( $value ) ) {
			return [ 'text', "", null ];
		}
		$input_types = [
				'text',
				'password',
				'textarea',
				'email',
				'search',
				'tel',
				'file',
				'number',
				'url',
				'time',
				'date',
				'select',
			];
		// [first name]
		// [first name=text]
		// [email]
		// [email=email]
		// [select=a,b,c]
		// [Select option=select=a,b,c]
		// [textarea=500]
		// [enter=textarea=500]
		// [enter text=textarea=500]
		[ $a, $b, $c ] = preg_split( "/\s*=\s*/", $value, -1, PREG_SPLIT_NO_EMPTY ) + [ "", null, null ];
		if ( $b && $c ) {
			return [ $b, $a, $c ];
		}
		if ( in_array( $b, $input_types ) ) {
			return [ $b, $a, null ];
		}
		if ( in_array( $a, $input_types ) ) {
			return [ $a, "", $b ];
		}
		if ( !$b ) {
			$b = 'text';
		}
		return [ $b, $a, null ];
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function ci_form_section( Parser $parser, ...$argv ) {
		$output = self::ci_form_section_process( $parser->getTitle(), $argv );
		// @phan-suppress-next-line SecurityCheck-XSS
		return [ $output, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * @param int $db
	 * @return \Wikimedia\Rdbms\DBConnRef|\Wikimedia\Rdbms\IDatabase|\Wikimedia\Rdbms\IReadableDatabase
	 */
	public static function getDB( $db ) {
		if ( !method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// @see https://gerrit.wikimedia.org/r/c/mediawiki/extensions/PageEncryption/+/1038754/comment/4ccfc553_58a41db8/
			return MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( $db );
		}
		$connectionProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		switch ( $db ) {
			case DB_PRIMARY:
				return $connectionProvider->getPrimaryDatabase();
			case DB_REPLICA:
			default:
				return $connectionProvider->getReplicaDatabase();
		}
	}

}
