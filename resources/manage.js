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
 * @author thomas-topway-it <thomas.topway.it@mail.com>
 * @copyright Copyright © 2021-2024, https://wikisphere.org
 */

$( document ).ready( () => {
	// display every 3 days
	if ( !mw.cookie.get( 'ciforms-check-latest-version' ) ) {
		mw.loader.using( 'mediawiki.api', () => {
			new mw.Api()
				.postWithToken( 'csrf', {
					action: 'ciforms-check-latest-version'
				} )
				.done( ( res ) => {
					if ( 'ciforms-check-latest-version' in res ) {
						if ( res[ 'ciforms-check-latest-version' ].result === 2 ) {
							const messageWidget = new OO.ui.MessageWidget( {
								type: 'warning',
								label: new OO.ui.HtmlSnippet(
									mw.msg( 'ciforms-jsmodule-outdated-version' )
								),
								// *** this does not work before ooui v0.43.0
								showClose: true
							} );
							const closeFunction = function () {
								const three_days = 3 * 86400;
								mw.cookie.set( 'ciforms-check-latest-version', true, {
									path: '/',
									expires: three_days
								} );
								$( messageWidget.$element ).parent().remove();
							};
							messageWidget.on( 'close', closeFunction );
							$( '.ciforms-manage-pager-table' )
								.eq( 0 )
								.before( $( '<div><br/></div>' ).prepend( messageWidget.$element ) );

							if (
								!messageWidget.$element.hasClass(
									'oo-ui-messageWidget-showClose'
								)
							) {
								messageWidget.$element.addClass(
									'oo-ui-messageWidget-showClose'
								);
								const closeButton = new OO.ui.ButtonWidget( {
									classes: [ 'oo-ui-messageWidget-close' ],
									framed: false,
									icon: 'close',
									label: OO.ui.msg( 'ooui-popup-widget-close-button-aria-label' ),
									invisibleLabel: true
								} );
								closeButton.on( 'click', closeFunction );
								messageWidget.$element.append( closeButton.$element );
							}
						}
					}
				} );
		} );
	}

	// $( '.ciforms-manage-button-select' ).each( function () {
	// @see https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki
	// var checkBox = OO.ui.infuse( $( this ) );
	// } );

	let selected = false;
	$( '#ci-forms-manage-pager-button-select-all' ).on( 'click', ( evt ) => {
		selected = !selected;
		$( '.ciforms-manage-button-select' ).each( function () {
			// @see https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki
			const checkBox = OO.ui.infuse( $( this ) );
			checkBox.setSelected( selected );
		} );
	} );

	$( '#ci-forms-manage-pager-button-delete-selected' ).on( 'click', ( evt ) => {
		const arr = [];
		$( '.ciforms-manage-button-select' ).each( function () {
			const checkBox = OO.ui.infuse( $( this ) );
			if ( checkBox.isSelected() ) {
				arr.push( checkBox.getData().id );
			}
		} );

		if ( !arr.length ) {
			return false;
		}

		if ( !confirm( mw.msg( 'ciforms-jsmodule-confirm-delete' ) ) ) {
			return false;
		}

		const url = window.location.href;
		const form = $( '<form>', {
			action: window.location.href,
			method: 'POST'
			// 'target': '_top'
		} ).append( $( '<input>', {
			name: 'delete',
			value: arr.join( ',' )
		} ) );
		$( document.body ).append( form );
		form.submit();
		return false;
	} );

	$( '.ciforms-manage-button-export' ).each( function () {
		const $buttonExport = $( this );

		const href = $buttonExport.data().ooui.href;

		const buttonMenu = new OO.ui.ButtonMenuSelectWidget( {
			label: mw.msg( 'ci-forms-manage-pager-button-export' ),
			icon: 'menu',
			flags: [ 'progressive', 'primary' ],
			menu: {
				items: [
					new OO.ui.MenuOptionWidget( {
						data: 'csv',
						label: mw.msg( 'ci-forms-manage-pager-button-export-csv' )
					} ),
					new OO.ui.MenuOptionWidget( {
						data: 'excel',
						label: mw.msg( 'ci-forms-manage-pager-button-export-excel' )
					} )
				]
			}
		} );

		const panelLayout = new OO.ui.PanelLayout( {
			padded: false,
			expanded: false,
			classes: [ 'ci-forms-manage-pager-panel-layout' ]
		} );

		buttonMenu.getMenu().on( 'choose', ( menuOption ) => {
			const data = menuOption.getData();
			window.location.assign( href.replace( 'format=csv', 'format=' + data ) );
		} );

		$buttonExport.replaceWith( panelLayout.$element.append( buttonMenu.$element ) );
	} );
} );
