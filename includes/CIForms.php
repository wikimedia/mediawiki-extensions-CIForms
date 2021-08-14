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
 * @author thomas-topway-it <thomas.topway.it@mail.com>
 * @copyright Copyright © 2021, https://culturaitaliana.org
 */
class CIForms
{
	protected static $loadModule = false;


	// Register any render callbacks with the parser
	public static function ParserFirstCallInit(Parser $parser)
	{
		$parser->setFunctionHook('ci form', [self::class, 'ci_form']);	//,SFH_OBJECT_ARGS
		$parser->setFunctionHook('ci_form', [self::class, 'ci_form']);	//,SFH_OBJECT_ARGS

		$parser->setFunctionHook('ci form section', [self::class, 'ci_form_section']);
		$parser->setFunctionHook('ci_form_section', [self::class, 'ci_form_section']);
	}

	public static function CaptchaEnabled()
	{
		global $wgCIFormsGoogleRecaptchaSiteKey;
		global $wgCIFormsGoogleRecaptchaSecret;

		return (!empty($wgCIFormsGoogleRecaptchaSiteKey) && !empty($wgCIFormsGoogleRecaptchaSiteKey));
	}

	public static function BeforePageDisplay(OutputPage $outputPage, Skin $skin)
	{
		$title = $outputPage->getTitle();
		$categories = $title->getParentCategories();
		
		//if(self::$loadModule) {
		if (array_key_exists('Category:Pages_with_forms', $categories)) {
			global $wgCIFormsGoogleRecaptchaSiteKey;
			global $wgResourceBasePath;

			$outputPage->addModules('ext.CIForms.validation');

			if (self::CaptchaEnabled()) {
				$outputPage->addJsConfigVars(['ci_forms_google_recaptcha_site_key' => $wgCIFormsGoogleRecaptchaSiteKey]);
				$outputPage->addHeadItem('captcha_style', '<style>.grecaptcha-badge { visibility: hidden; display: none; }</style>');
			}

			$css = '<link rel="stylesheet" href="' . $wgResourceBasePath . '/extensions/CIForms/resources/style.css" />';

			$outputPage->addHeadItem('ci_forms_css', $css);
		}
	}

	public static function ci_form(Parser $parser, ...$argv)
	{
		self::$loadModule = true;

		global $wgCIFormsSuccessMessage;
		global $wgCIFormsErrorMessage;
		global $wgCIFormsSubmitEmail;

		$named_parameters = [
			'submit' => $wgCIFormsSubmitEmail,
			'title' => null,
			'success message' => null,	//$wgCIFormsSuccessMessage,
			'error message' => null,	//$wgCIFormsErrorMessage
			'form class' => '',	//$wgCIFormsErrorMessage

		]; // email to which submit

		$parser->addTrackingCategory('ci-form');

		$set_named_parameters = [];

		$lines = self::parse_function_arguments($argv, $named_parameters, $set_named_parameters);

		$section_lines = [];

		foreach ($lines as $key => $value) {

			// https://www.mediawiki.org/wiki/Strip_marker

			// *** to-do add some logic to fix a missing pipe

			if (!preg_match('/^(\x7F\'"`UNIQ.+?QINU`"\'\x7F){1}(\s+\x7F\'"`UNIQ.+?QINU`"\'\x7F)*$/', $value)) {
				unset($lines[$key]);
				$section_lines[] = $value;
			} else {
				$lines[$key] = preg_replace('/\s+/', " ", $value);
			}
		}

		$output = '';

		$url = Title::newFromText('Special:CIFormsSubmit')->getLocalURL();

		$output .= '<form class="ci_form' . (!empty($named_parameters['form class']) ? " " . htmlspecialchars($named_parameters['form class']) : '') . '" action="' . $url . '" method="post">';
		$output .= '<div class="ci_form_container">';




		// allow wiki-text and html in titles
		if (!empty($named_parameters['title'])) {
			$named_parameters['title'] = self::replace_wikitext_and_html($named_parameters['title']);
		}

		if (!empty($named_parameters['title'])) {
			$output .= '<div class="ci_form_title">';
			$output .= self::replace_wikitext_and_html($named_parameters['title']);
			$output .= '</div>';
		}

		$output .= '<div class="ci_form_sections_container' . (sizeof($lines) ? ' multiple_sections' : '') . '">';

		if ($section_lines) {
			$output .= self::ci_form_section_process($section_lines);
		}

		if (sizeof($lines)) {
			$output .= implode($lines);
		}

		$output .= '</div>';

		$output .= '<div class="ci_form_section_submit">';

		$output .= '<input type="hidden" name="form_title" value="' . htmlspecialchars($named_parameters['title']) . '">';
		$output .= '<input type="hidden" name="form_submit" value="' . htmlspecialchars($named_parameters['submit']) . '">';
		$output .= '<input type="hidden" name="form_success-message" value="' . htmlspecialchars($named_parameters['success message']) . '">';
		$output .= '<input type="hidden" name="form_error-message" value="' . htmlspecialchars($named_parameters['error message']) . '">';

		if (self::CaptchaEnabled()) {
			$output .= '<input type="hidden" name="g-recaptcha-response">';
		}

		$title = $parser->getTitle();

		$output .= '<input type="hidden" name="form_pagename" value="' . htmlspecialchars($title->getText()) . '">';

		$output .= '<input class="ci_form_input_submit" type="submit" value="Submit">';

		$output .= '</div>';

		$output .= '</div>';

		$output .= '<div class="ci_form_section_captcha">';
		if (self::CaptchaEnabled()) {
			$output .= 'form protected using <a target="_blank" style="color:silver;text-decoration:" href="https://www.google.com/recaptcha/about/">Google recaptcha</a>';
		}
		$output .= '</div>';

		$output .= '</form>';

		return array($output, 'noparse' => true, 'isHTML' => true);
	}

	public static function ci_form_parse_input_symbol($value)
	{
		$input_type = 'text';
		$placeholder = null;

		if (empty($value)) {
			return [$input_type, $placeholder];
		}


		// https://quasar.dev/vue-components/input
		// text password textarea email search tel file number url time date

		$input_types = ['text', 'password', 'textarea', 'email', 'search', 'tel', 'file', 'number', 'url', 'time', 'date'];


		// [first name]
		// [first name=text]
		// [email]

		list($a, $b) = explode('=', $value) + array(null, null);

		if ($b) {
			$input_type = $b;
			$placeholder = $a;
		} else {
			if (in_array($a, $input_types)) {
				$input_type = $a;
			} else {
				$placeholder = $a;
			}
		}

		return [$input_type, $placeholder];
	}

	protected static function ci_form_section_process($argv)
	{
		$output = '';


		// default values
		$named_parameters = [
			'type' => 'inputs',		// 'inputs', 'inputs resposive', 'multiple choice', 'cloze', 'cloze-test'
			'title' => null,
			'list-type' => 'none',	// 'unordered', 'letters', 'numbers' + standard values
			'max answers' => 1,
			'suggestions' => null	// if multiple choice
		];

		$set_named_parameters = [];

		$lines = self::parse_function_arguments($argv, $named_parameters, $set_named_parameters);


		// alias
		if ($named_parameters['type'] == 'cloze') {
			$named_parameters['type'] = 'cloze test';
		}


		// alias
		if ($named_parameters['type'] == 'input') {
			$named_parameters['type'] = 'inputs';
		}

		
		// cloze test list type default value
		if (!in_array('list-type', $set_named_parameters) && $named_parameters['type'] == 'cloze test') {
			$named_parameters['list-type'] = 'ordered';
		}

		$unique_id = uniqid();

		$output .= '<div class="ci_form_section ' . htmlspecialchars(str_replace(' ', '_', $named_parameters['type'])) . '" data-id="' . $unique_id . '">';

		switch ($named_parameters['type']) {
			case 'cloze test':
			case 'multiple choice':
				$ordered_styles = [
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
					'upper-alpha'
				];

				if (in_array($named_parameters['list-type'], $ordered_styles)) {
					$list_style = $named_parameters['list-type'];
				} else {
					switch ($named_parameters['list-type']) {
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

				$output .= '<input type="hidden" name="' . $unique_id . '_section_list-style" value="' . htmlspecialchars($named_parameters['list-type']) . '">';

				if ($named_parameters['type'] == 'multiple choice') {
					$output .= '<input type="hidden" name="' . $unique_id . '_section_multiple-choice-max-answers" value="' . htmlspecialchars($named_parameters['max answers']) . '">';
				}

				break;

			case 'inputs':
			case 'inputs responsive':
				break;
		}



		// allow wiki-text and html in titles
		if (!empty($named_parameters['title'])) {
			$named_parameters['title'] = self::replace_wikitext_and_html($named_parameters['title']);
		}

		$output .= '<input type="hidden" name="' . $unique_id . '_section_type" value="' . htmlspecialchars($named_parameters['type']) . '">';
		$output .= '<input type="hidden" name="' . $unique_id . '_section_title" value="' . htmlspecialchars($named_parameters['title']) . '">';

		if (!empty($named_parameters['title'])) {
			$output .= '<div class="ci_form_section_title">';
			$output .= $named_parameters['title'];
			$output .= '</div>';
		}

		switch ($named_parameters['type']) {
			case 'inputs':
			case 'inputs responsive':
				$n = 0;

				foreach ($lines as $value) {
					$output .= '<div class="ci_form_section_inputs_row">';

					$output .= '<div class="ci_form_section_inputs_col' . ($named_parameters['type'] == 'inputs responsive' ? '-25' : '') . '">';

					$output .= '<input type="hidden" name="' . $unique_id . '_items_' . $n . '_label" value="' . htmlspecialchars($value) . '" />';

					$i = 0;

					$output .= preg_replace_callback(
						'/([^\[\]]*)\[([^\[\]]*)\]\s*(\*)?/',
						function($matches) use ($named_parameters, &$i, $n, $unique_id) {

						// *** todo, redesign to allow more than 1 input per line
							if ($i > 0) {
								return $matches[0];
							}

							$label = $matches[1];

							list($input_type, $placeholder) = self::ci_form_parse_input_symbol($matches[2]);

							$required = (!empty($matches[3]) ? ' data-required="1" required' : '');

							if ($required && !empty($placeholder)) {
								$placeholder .= ' *';
							}

							$replacement = '';

							if (!empty($label)) {
								$replacement .= '<label>' . $label . ($required && empty($placeholder) ? ' *' : '') . '</label>';
							}

							if ($named_parameters['type'] == 'inputs responsive') {
								$replacement .= '</div>';
								$replacement .= '<div class="ci_form_section_inputs_col-75">';
							}

							switch ($input_type) {
								case 'textarea':
								// '_value' is appended for easy validation
									$replacement .= '<textarea rows="4" name="' . $unique_id . '_items_' . $n . '_input_' . $i . '_value"' . ($placeholder ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '') . $required . '></textarea>';
									break;

								default:
								case 'text':
								case 'email':
								// '_value' is appended for easy validation
									$replacement .= '<input name="' . $unique_id . '_items_' . $n . '_input_' . $i . '_value" type="' . $input_type . '"' . ($placeholder ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '') . $required . '/>';
									break;
							}

							$i++;

							return $replacement;
						},
						$value
					); // preg_replace_callback

					$output .= '</div>';

					$output .= '</div>';

					$n++;
				}

				break;

			case 'multiple choice':
				$list_type_ordered = in_array($list_style, $ordered_styles);


				// https://stackoverflow.com/questions/23699128/how-can-i-reset-a-css-counter-to-the-start-attribute-of-the-given-list 

				if (!$list_type_ordered) {
					$output .= '<ul class="ci_form_section_multiple_choice_list" style="--list_style_type:' . $list_style . '">';
				} else {
					$output .= '<ol class="ci_form_section_multiple_choice_list" style="--list_style_type:' . $list_style . '">';
				}

				$n = 0;


				// native validation, see the following:

				// https://stackoverflow.com/questions/8287779/how-to-use-the-required-attribute-with-a-radio-input-field
				// https://stackoverflow.com/questions/6218494/using-the-html5-required-attribute-for-a-group-of-checkboxes

				$output .= ($named_parameters['max answers'] > 1 ? '<input class="radio_for_required_checkboxes" type="radio" name="' . uniqid() . '" required/>' : '');

				foreach ($lines as $key => $value) {
					$output .= '<li>';

					$output .= '<input type="hidden" name="' . $unique_id . '_items_' . $n . '_label" value="' . htmlspecialchars($value) . '" />';

					// if it's a radio, the input name shall be the same for all inputs
					$output .= '<input name="' . $unique_id . '_items_' . ($named_parameters['max answers'] > 1 ? $n . '_' : '') . 'selected" type="' . ($named_parameters['max answers'] == 1 ? 'radio' : 'checkbox') . '" value="' . $n . '"' . ($named_parameters['max answers'] == 1 ? ' required' : '') . ' />';

					$i = 0;

					$output .= preg_replace_callback(
						'/\[([^\[\]]*)\]/',
						function($matches) use (&$i, $n, $unique_id) {
							$replacement = '<input name="' . $unique_id . '_items_' . $n . '_input_' . $i . '" type="text" data-required="1" />';

							$i++;

							return $replacement;
						},
						$value
					); // preg_replace_callback

					$output .= '</li>';

					$n++;
				}

				$output .= ($list_type_ordered ? '</ol>' : '</ul>');

				break;

			case 'cloze test':
				$suggestions = [];

				if (!empty($named_parameters['suggestions'])) {
					$suggestions = explode(',', $named_parameters['suggestions']);

					foreach ($suggestions as $key => $word) {
						$suggestions[$key] = trim(strtolower($word));
					}
				}

				$items = [];
				$answers = [];

				foreach ($lines as $key => $value) {
					$example = false;
					$inline_answer = null;

					$value = trim($value);
					$value = preg_replace('/\s+/', ' ', $value);


					// *** to-do
					// in a cloze test the asterisk used
					// to mark an example is redundant

					if ($value[0] == '*') {
						$example = true;
						$value = trim(substr($value, 1));
					}

					preg_match_all('/\[\s*([^\[\]]*)\s*\]/', $value, $matches);

					$inputs = [];

					if (!empty($matches[0])) {
						foreach ($matches[0] as $i => $match) {
							$inline_answer = null;

							$inline_suggestion = strtolower($matches[1][$i]);

							if ($inline_suggestion) {
								preg_match('/^\s*(.+?)\s*=\s*(.+?)\s*$/', $inline_suggestion, $match_);

								if (!empty($match_[1])) {
									$inline_suggestion = strtolower($match_[1]);

									if (!empty($match_[2])) {
										$inline_answer = strtolower($match_[2]);
									}
								}

								if ($example) {
									$answers[] = $inline_suggestion;	//($inline_answer ?? $inline_suggestion);
								}
							}

							$inputs[] = [$inline_suggestion, $inline_answer];
						}
					}

					$items[] = [$value, $example, $inputs];
				}

				shuffle($suggestions);

				$output .= '<input type="hidden" name="' . $unique_id . '_section_cloze-test-suggestions" value="' . htmlspecialchars(implode(',', $suggestions)) . '" />';
				$output .= '<input type="hidden" name="' . $unique_id . '_section_cloze-test-answers" value="' . htmlspecialchars(implode(',', $answers)) . '" />';
				

				// suggestions framed
				if (!empty($suggestions)) {
					$output .= '<div class="ci_form_section_cloze_test_suggestions">';

					foreach ($suggestions as $word) {
						$output .= '<span class="ci_form_section_cloze_test_suggestions_word' . (in_array($word, $answers) ? '_answered' : '') . '">';
						$output .= $word;
						$output .= '</span>';

						if (in_array($word, $answers)) {
							$key = array_search($word, $answers);
							unset($answers[$key]);
						}
					}

					$output .= '</div>';
				}

				$list_type_ordered = in_array($list_style, $ordered_styles);


				// https://stackoverflow.com/questions/23699128/how-can-i-reset-a-css-counter-to-the-start-attribute-of-the-given-list 

				if (!$list_type_ordered) {
					$output .= '<ul class="ci_form_section_cloze_test_list" style="--list_style_type:' . $list_style . '">';
				} else {
					$output .= '<ol class="ci_form_section_cloze_test_list" style="--list_style_type:' . $list_style . '">';
				}

				$n = 0;

				foreach ($items as $value) {
					list($label, $example, $inputs) = $value;

					$output .= '<li class="ci_form_section_cloze_test_list_question' . ($example ? '_example' : '') . '">';

					$output .= '<input type="hidden" name="' . $unique_id . '_items_' . $n . '_label" value="' . htmlspecialchars(($example ? '* ' : '') . $label) . '" />';

					$i = 0;

					$label = preg_replace_callback(
						'/\[([^\[\]]*)\]/',
						function($matches) use (&$i, &$inputs, $example, $n, $unique_id) {
							list($inline_suggestion, $inline_answer) = array_shift($inputs);

							$replacement = '';

							if ($inline_suggestion) {
								$replacement .= '<span class="ci_form_section_cloze_test_section_list_question_suggestion">(' . $inline_suggestion . ')</span> ';
							}

							if ($example) {
								$replacement .= '<span class="ci_form_section_cloze_test_list_question_answered">' . ($inline_answer ? $inline_answer : $inline_suggestion) . '</span>';
							} else {
							// '_value' is appended for easy validation
								$replacement .= '<input name="' . $unique_id . '_items_' . $n . '_input_' . $i . '_value" type="text" />';
							}

							$i++;

							return $replacement;
						},
						$label
					); // preg_replace_callback

					$output .= $label;
					$output .= '</li>';

					$n++;
				}

				$output .= ($list_type_ordered ? '</ol>' : '</ul>');

				break;
		}

		$output .= '</div>';

		return $output;
	}

	protected static function replace_wikitext_and_html($value)
	{
		$context = new RequestContext();
		$out_ = new OutputPage($context);

		$unique_id = uniqid();

		$replacements = [];

		$value = preg_replace_callback(
			'/<[^<>]+>/',
			function($matches) use (&$replacements, $unique_id) {
				$replacements[] = $matches[0];

				return $unique_id;
			},
			$value
		);

		$value = Parser::stripOuterParagraph($out_->parseAsContent($value));

		$value = preg_replace_callback(
			'/' . $unique_id . '/',
			function($matches) use (&$replacements, $unique_id) {
				return array_shift($replacements);
			},
			$value
		);

		return $value;
	}





	// check also here
	// https://www.mediawiki.org/wiki/Manual:Parser_functions#The_setFunctionHook_hook

	protected static function parse_function_arguments($argv, &$named_parameters, &$set_named_parameters)
	{
		$lines = [];

		//$set_named_parameters = [];
		$unique_id = uniqid();

		foreach ($argv as $value) {
			$value = trim($value);
			$value = preg_replace('/\040+/', ' ', $value);

			if (empty($value)) {
				continue;
			}


			// square brackets may contain an equal symbol
			// so we temporarily remove it
			//$value_ = preg_replace('/\[\s*(.+?)\s*\]\s*\*?/','',$value);



			// replace html and square brackets with some identifier

			$replacements = [];
			$value_ = preg_replace_callback(
				'/(<[^<>]+>)|(\[[^\[\]]+\])/',
				function($matches) use (&$replacements, $unique_id) {
					if ($matches[1]) {
						$replacements[] = '<html>' . $matches[0] . '</html>';
					} else {
						$replacements[] = $matches[0];
					}

					return $unique_id;
				},
				$value
			);

			if (strpos($value_, '=') !== false) {
				list($parameter_key, $parameter_value) = explode('=', $value_, 2);

				$parameter_key = trim(str_replace('_', ' ', $parameter_key));

				if (array_key_exists($parameter_key, $named_parameters)) {
					$parameter_value = preg_replace_callback(
						'/' . $unique_id . '/',
						function($matches) use (&$replacements, $unique_id) {
							return array_shift($replacements);
						},
						$parameter_value
					);

					$named_parameters[$parameter_key] = trim($parameter_value);

					$set_named_parameters[] = $parameter_key;

					continue;
				}
			}

			$lines[] = $value;
		}

		return $lines;
	}

	public static function ci_form_section(Parser $parser, ...$argv)
	{
		$output = self::ci_form_section_process($argv);

		return array($output, 'noparse' => true, 'isHTML' => true);
	}
}


