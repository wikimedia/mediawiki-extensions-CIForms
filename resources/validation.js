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
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright © 2021-2024, https://wikisphere.org
 */

$( () => {
	const msg1 = mw.config.get( 'ci-forms-validation-msg1' );
	const msg2 = mw.config.get( 'ci-forms-validation-msg2' );
	// var msg3 = mw.config.get( 'ci-forms-validation-msg3' );

	const currentSection = {};

	function escape( s ) {
		return String( s )
			.replace( /&/g, '\x26' )
			.replace( /'/g, '\x27' )
			.replace( /"/g, '\x22' )
			.replace( /</g, '\x3C' )
			.replace( />/g, '\x3E' );
	}

	const site_key = mw.config.get( 'ci_forms_google_recaptcha_site_key' );

	// @see https://www.mediawiki.org/wiki/Topic:Y2pfh94nkkqzsjw3
	function executeRecaptchaValidation() {
		mw.loader
			.getScript( 'https://www.google.com/recaptcha/api.js?render=' + site_key )
			.then(
				() => {
					if ( $( 'input[name="g-recaptcha-response"]' ).length ) {
						grecaptcha.ready( () => {
							grecaptcha
								.execute( site_key, { action: 'validate_captcha' } )
								.then( ( token ) => {
									$( 'input[name="g-recaptcha-response"]' ).val( token );
								} )
								.catch( ( error ) => {
									// eslint-disable-next-line no-console
									console.log( error );
								} );
						} );
					}
				},
				( e ) => {
					mw.log.error( e.message );
				}
			);
	}

	if ( site_key ) {
		executeRecaptchaValidation();

		// refresh each 90 seconds
		setInterval( executeRecaptchaValidation, 90 * 1000 );
	}

	$( '.ci_form' ).each( function ( index ) {
		const paging = $( this )
			.find( 'input[type=hidden][name=form_paging]' )
			.first()
			.val();

		if ( paging && paging !== 'false' ) {
			$( this ).find( '.ci_form_input_navigation_next' ).attr( 'type', 'submit' );
		}

		currentSection[ index ] = 0;
		$( this )
			.find(
				( paging && paging !== 'false' ?
					'.ci_form_section_display_' + currentSection[ index ] + ' ' :
					'' ) + ':input[data-required="1"]'
			)
			.prop( 'required', true );
		$( this ).data( 'form-index', index );
	} );

	$( '.ci_form li' ).each( function ( index ) {
		const el = this;
		const section_el = $( this ).closest( '.ci_form_section' );
		const radioForCheckboxes = $( section_el )
			.find( '.radio_for_required_checkboxes' )
			.first();
		const max_answers = $( section_el )
			.find( 'input[type=hidden][name$=_multiple-choice-max-answers]' )
			.val();

		$( this )
			.find( 'input[type=text]' )
			.on( 'click', function () {
				const count = $( section_el ).find( 'input[type=checkbox]:checked' ).length;

				if ( count > max_answers ) {
					alert( msg1.replace( '$1', max_answers ) );
					return false;
				}

				if ( $( this ).attr( 'data-required' ) === 1 ) {
					$( this ).prop( 'required', true );
				}

				$( el ).find( 'input[type=radio]' ).prop( 'checked', true );
				$( el ).find( 'input[type=checkbox]' ).prop( 'checked', true );

				radioForCheckboxes[ 0 ].checked = !!count;
			} );

		$( this )
			.find( 'input[type=checkbox]' )
			.on( 'click', () => {
				const count = $( section_el ).find( 'input[type=checkbox]:checked' ).length;

				if ( count > max_answers ) {
					alert( msg1.replace( '$1', max_answers ) );
					return false;
				}
				radioForCheckboxes[ 0 ].checked = !!count;
			} );
	} );

	// https://stackoverflow.com/questions/15031513/jquery-help-to-enforce-maxlength-on-textarea
	$( '.ci_form textarea[maxlength]' ).on( 'keyup', function () {
		const limit = parseInt( $( this ).attr( 'maxlength' ) );
		const text = $( this ).val();
		const chars = text.length;

		$( this )
			.parents()
			.each( function () {
				const span = $( this )
					.find( '.ci_form_section_inputs_textarea_maxlength' )
					.first();

				if ( span.length ) {
					span.html( chars + '/' + limit + ' characters' );
				}
			} );

		if ( chars > limit ) {
			const new_text = text.slice( 0, Math.max( 0, limit ) );
			$( this ).val( new_text );
		}
	} );

	$( '.ci_form select' ).each( function () {
		if ( $( this ).find( 'option' ).length > 20 ) {
			$( this ).select2();
		}
	} );

	// we cannot use form on submit because
	// is triggered after the native validation

	$( '.ci_form input[type=radio]' ).on( 'click', function () {
		const section_el = $( this ).closest( '.ci_form_section' );

		$( section_el )
			.find( 'li' )
			.each( function () {
				const el = this;

				$( this )
					.find( 'input[type=radio][name$=_selected]:checked' )
					.each( () => {
						$( el )
							.find( 'input[type=text][data-required="1"]' )
							.prop( 'required', true );
					} );

				$( this )
					.find( 'input[type=radio][name$=_selected]:not(:checked)' )
					.each( () => {
						$( el ).find( 'input[type=text]' ).removeAttr( 'required' );
					} );
			} );
	} );

	$( '.ci_form input[type=checkbox]' ).on( 'click', function () {
		const section_el = $( this ).closest( '.ci_form_section' );

		$( section_el )
			.find( 'li' )
			.each( function () {
				const el = this;

				$( this )
					.find( 'input[type=checkbox][name$=_selected]:checked' )
					.each( () => {
						$( el )
							.find( 'input[type=text][data-required="1"]' )
							.prop( 'required', true );
					} );

				$( this )
					.find( 'input[type=checkbox][name$=_selected]:not(:checked)' )
					.each( () => {
						$( el ).find( 'input[type=text]' ).removeAttr( 'required' );
					} );
			} );
	} );

	$( '.ci_form_section_submit button' ).on( 'click', function ( evt ) {
		const form_el = $( this ).closest( '.ci_form' );

		const next = $( this ).prop( 'class' ).includes( 'next' );

		if ( next ) {
			return;
		}

		form_el.get( 0 ).scrollIntoView();

		const index = form_el.data( 'form-index' );
		const current_section = currentSection[ index ] + ( next ? 1 : -1 );

		const count =
			$( form_el ).find( "[class^='ci_form_section_display_']" ).length - 1;

		// $(form_el).find(".ci_form_section").length - 1;

		if ( current_section < 0 || current_section > count ) {
			return;
		}

		$( form_el )
			.find( '.ci_form_section_display_' + currentSection[ index ] )
			.first()
			.hide();

		$( form_el )
			.find(
				'.ci_form_section_display_' +
					currentSection[ index ] +
					' :input[data-required="1"]'
			)
			.removeAttr( 'required' );

		currentSection[ index ] = current_section;

		$( form_el )
			.find( '.ci_form_section_display_' + current_section )
			.first()
			.fadeIn( 'slow' );
		$( form_el )
			.find( '.ci_form_input_navigation_back' )
			.first()
			.css( 'display', current_section ? 'inline-block' : 'none' );
		$( form_el )
			.find( '.ci_form_input_navigation_next' )
			.first()
			.css( 'display', current_section !== count ? 'inline-block' : 'none' );
		$( form_el )
			.find( '.ci_form_input_submit' )
			.first()
			.css( 'display', current_section === count ? 'inline-block' : 'none' );
	} );

	$( '.ci_form' ).on( 'submit', function ( evt ) {
		const form_el = $( this );

		form_el.get( 0 ).scrollIntoView();

		const paging = $( this )
			.find( 'input[type=hidden][name=form_paging]' )
			.first()
			.val();

		let index, current_section, count;

		if ( paging && paging !== 'false' ) {
			index = form_el.data( 'form-index' );
			current_section = currentSection[ index ] + 1;
			count = $( form_el ).find( "[class^='ci_form_section_display_']" ).length - 1;

			// $(form_el).find(".ci_form_section").length - 1;
		}

		let preventSubmit = false;
		$( this )
			.find(
				( paging && paging !== 'false' ?
					'.ci_form_section_display_' + currentSection[ index ] + ' ' :
					'' ) + '.ci_form_section'
			)
			.each( function () {
				const section_type = $( this )
					.find( 'input[type=hidden][name$=_section_type]' )
					.val();

				const min_answers = $( this )
					.find( 'input[type=hidden][name$=_multiple-choice-min-answers]' )
					.val();

				let question_name = $( this ).find( '.ci_form_section_title' ).text();

				if ( !question_name ) {
					question_name = $( form_el ).find( '.ci_form_title' ).text();
				}

				switch ( section_type ) {
					case 'cloze test':
						var inputs = 0;
						var filledIn = 0;

						$( this )
							.find( 'input[type=text][name$=_value]' )
							.each( function () {
								const val = $( this ).val().trim();

								if ( val !== '' && val !== null ) {
									filledIn++;
								}
								inputs++;
							} );

						var minNumber = min_answers || Math.floor( inputs / 2 ) + 1;

						if ( filledIn < minNumber ) {
							alert(
								msg2
									.replace( '$1', minNumber )
									.replace( '$2', escape( question_name ) )
							);
							preventSubmit = true;
							return false;
						}

						break;

					case 'multiple choice':
						if ( min_answers ) {
							const checked = $( this ).find(
								'input[type=checkbox][name$=_selected]:checked'
							).length;
							if ( checked < min_answers ) {
								alert(
									msg2
										.replace( '$1', min_answers )
										.replace( '$2', escape( question_name ) )
								);
								preventSubmit = true;
								return false;
							}
						}

						break;
				}
			} );

		if ( preventSubmit ) {
			evt.preventDefault();
			return false;
		}

		if ( paging && paging !== 'false' ) {
			if ( current_section <= count ) {
				$( form_el )
					.find( '.ci_form_section_display_' + currentSection[ index ] )
					.first()
					.hide();

				// next section
				currentSection[ index ] = current_section;

				$( form_el )
					.find( '.ci_form_section_display_' + current_section )
					.first()
					.fadeIn( 'slow' );

				$( this )
					.find( '.ci_form_section_display_' + current_section )
					.find( ':input[data-required="1"]' )
					.prop( 'required', true );
			}

			$( form_el )
				.find( '.ci_form_input_navigation_back' )
				.first()
				.css( 'display', current_section ? 'inline-block' : 'none' );
			$( form_el )
				.find( '.ci_form_input_navigation_next' )
				.first()
				.css( 'display', current_section !== count ? 'inline-block' : 'none' );
			$( form_el )
				.find( '.ci_form_input_submit' )
				.first()
				.css( 'display', current_section === count ? 'inline-block' : 'none' );

			if ( current_section <= count ) {
				evt.preventDefault();
				return false;
			}
		}
	} );
} );
