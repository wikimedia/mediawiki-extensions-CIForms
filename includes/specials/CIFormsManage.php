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
 * along with Wiskiphere_Subpages.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2021-2024, https://wikisphere.org
 */

include_once __DIR__ . '/PrevNextNavigationRendererCIForms.php';

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

use MediaWiki\MediaWikiServices;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class CIFormsManage extends QueryPage {
	private $form_title;
	private $page_id;
	private $headers;
	private $entry;
	private $userGroups;
	private $dbType;
	private $escapedDBprefix;

	/**
	 * @param string $name
	 */
	public function __construct( $name = 'CIFormsManage' ) {
		parent::__construct( $name );
	}

	/**
	 * @return string|Message
	 */
	public function getDescription() {
		$request = $this->getRequest();
		$form_title = $request->getVal( 'form_title' );

		if ( empty( $form_title ) ) {
			$ret = $this->msg( strtolower( $this->mName ) );
		} else {
			$ret = $this->msg( strtolower( $this->mName ) . '-title', $form_title );
		}

		if ( version_compare( MW_VERSION, '1.41', '<' ) ) {
			return $ret->text();
		}

		return $ret;
	}

	/**
	 * @param mixed $par
	 * @return void
	 */
	public function execute( $par ) {
		$this->requireLogin();
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Extension:CIForms' );

		$request = $this->getRequest();

		$this->form_title = $request->getVal( 'form_title' );
		$this->page_id = $request->getVal( 'page_id' );

		if ( $request->wasPosted() ) {
			$this->deleteItems( $request->getVal( 'delete' ) );
		}

		$this->userGroups = $this->getUserGroups();

		$download = $request->getVal( 'download' );

		if ( !empty( $download ) ) {
			$this->downloadPdf( $download );
		}

		global $wgDBprefix;

		$dbr = CIForms::getDB( DB_REPLICA );
		$this->dbType = $dbr->getType();
		$this->escapedDBprefix = ( $wgDBprefix ? preg_replace( '/[^A-Za-z0-9_ ]+/', '', $wgDBprefix ) : '' );

		$export = $request->getVal( 'export' );

		if ( !empty( $export ) ) {
			$this->export( $export, $request->getVal( 'format' ) );
		}

		$out = $this->getOutput();
		$out->addModuleStyles( $this->getModuleStyles() );
		$out->enableOOUI();
		$out->addModules( 'ext.CIForms.manage' );

		# this can be used to filter the search
		/*
		$out->addHTML(
			$this->showOptions(	)
		);
		*/

		if ( $this->isCached() && !$this->isCacheable() ) {
			$out->addWikiMsg( 'querypage-disabled' );
			return;
		}

		if ( !empty( $this->form_title ) ) {
			$special = SpecialPage::getTitleFor( 'CIFormsManage' );
			$out->addWikiMsg(
				'ci-forms-manage-pager-return',
				$special->getFullText()
			);
		}

		// ***taken from includes/QueryPage

		$out->setSyndicated( $this->isSyndicated() );

		if ( $this->limit == 0 && $this->offset == 0 ) {
			[ $this->limit, $this->offset ] = $this->getLimitOffset();
		}
		$dbLimit = $this->getDBLimit( $this->limit, $this->offset );

		// @todo Use doQuery()
		if ( !$this->isCached() ) {
			# select one extra row for navigation
			$res = $this->reallyDoQuery( $dbLimit, $this->offset );

			// ***edited
			if ( !empty( $this->form_title ) ) {
				$res = $this->mockupResults( $res );
			}
		} else {
			# Get the cached result, select one extra row for navigation
			$res = $this->fetchFromCache( $dbLimit, $this->offset );
			if ( !$this->listoutput ) {
				# Fetch the timestamp of this update
				$ts = $this->getCachedTimestamp();
				$lang = $this->getLanguage();
				$maxResults = $lang->formatNum( $this->getConfig()->get( 'QueryCacheLimit' ) );

				if ( $ts ) {
					$user = $this->getUser();
					$updated = $lang->userTimeAndDate( $ts, $user );
					$updateddate = $lang->userDate( $ts, $user );
					$updatedtime = $lang->userTime( $ts, $user );
					$out->addMeta( 'Data-Cache-Time', $ts );
					$out->addJsConfigVars( 'dataCacheTime', $ts );
					$out->addWikiMsg( 'perfcachedts', $updated, $updateddate, $updatedtime, $maxResults );
				} else {
					$out->addWikiMsg( 'perfcached', $maxResults );
				}

				# If updates on this page have been disabled, let the user know
				# that the data set won't be refreshed for now
				$disabledQueryPages = self::getDisabledQueryPages( $this->getConfig() );
				if ( isset( $disabledQueryPages[$this->getName()] ) ) {
					$runMode = $disabledQueryPages[$this->getName()];
					if ( $runMode === 'disabled' ) {
						$out->wrapWikiMsg(
							"<div class=\"mw-querypage-no-updates\">\n$1\n</div>",
							'querypage-no-updates'
						);
					} else {
						// Messages used here: querypage-updates-periodical
						$out->wrapWikiMsg(
							"<div class=\"mw-querypage-updates-" . $runMode . "\">\n$1\n</div>",
							'querypage-updates-' . $runMode
						);
					}
				}
			}
		}

		$this->numRows = $res->numRows();

		if ( !$this->numRows ) {
			$out->addWikiMsg(
				'ci-forms-manage-no-rows'
			);
			return;
		}

		$dbr = $this->getRecacheDB();
		$this->preprocessResults( $dbr, $res );
		$out->addHTML( Xml::openElement( 'div', [ 'class' => 'mw-spcontent' ] ) );

		// Top header and navigation

		// ***edited
		if ( $this->numRows > 20 || $this->offset > 0 ) {
		// if ( $this->shownavigation ) {
			$out->addHTML( $this->getPageHeader() );
			if ( $this->numRows > 0 ) {
				$out->addHTML( $this->msg( 'showingresultsinrange' )->numParams(
					min( $this->numRows, $this->limit ), # do not show the one extra row, if exist
					$this->offset + 1, ( min( $this->numRows, $this->limit ) + $this->offset ) )->parseAsBlock() );
				# Disable the "next" link when we reach the end
				$miserMaxResults = $this->getConfig()->get( 'MiserMode' )
					&& ( $this->offset + $this->limit >= $this->getMaxResults() );
				$atEnd = ( $this->numRows <= $this->limit ) || $miserMaxResults;
				$paging = $this->buildPrevNextNavigation( $this->offset,
					$this->limit, $this->linkParameters(), $atEnd, $par );
				$out->addHTML( '<p>' . $paging . '</p>' );
			} else {
				# No results to show, so don't bother with "showing X of Y" etc.
				# -- just let the user know and give up now
				$this->showEmptyText();
				$out->addHTML( Xml::closeElement( 'div' ) );
				return;
			}

		// ***edited
		} else {
			$out->addHTML( "<br />" );
		}

		if ( !empty( $this->form_title ) ) {
			$out->addHTML( "<br />" );
			$out->addHTML( new OOUI\ButtonWidget(
				[
					'label' => $this->msg( 'ci-forms-manage-pager-button-select-all' )->text(),
					'infusable' => true,
					'flags' => [ 'progressive' ],
					'id' => 'ci-forms-manage-pager-button-select-all',
				]
			) );
			$out->addHTML( new OOUI\ButtonWidget(
				[
					'label' => $this->msg( 'ci-forms-manage-pager-button-delete-selected' )->text(),
					'infusable' => true,
					'flags' => [ 'progressive', 'destructive' ],
					'id' => 'ci-forms-manage-pager-button-delete-selected',
					// 'href' => ''	// SpecialPage::getTitleFor( 'CIFormsManage' )->getLocalURL()
				]
			) );
			$out->addHTML( "<br />" );
			$out->addHTML( "<br />" );
		}

		$this->outputResults( $out,
			$this->getSkin(),
			$dbr, # Should use IResultWrapper for this
			$res,
			min( $this->numRows, $this->limit ), # do not format the one extra row, if exist
			$this->offset );

		// Repeat the paging links at the bottom

		// ***edited
		if ( $this->numRows > 20 || $this->offset > 0 ) {
		// if ($this->shownavigation) {
			$out->addHTML( '<p>' . $paging . '</p>' );
		}

		$out->addHTML( Xml::closeElement( 'div' ) );
	}

	/**
	 * @param string $sql
	 * @param bool $raw
	 * @return string
	 */
	private function sqlReplace( $sql, $raw = false ) {
		$dbr = CIForms::getDB( DB_REPLICA );

		if ( $this->dbType == 'postgres' ) {
			$sql = str_replace( 'CIForms_', 'ciforms_', $sql );
		}

		return $sql;
	}

	/**
	 * @return bool
	 */
	private function isSysop() {
		return in_array( 'sysop', $this->userGroups );
	}

	/**
	 * @param bool $raw
	 * @return string
	 */
	private function permissionsCond( $raw ) {
		$dbr = CIForms::getDB( DB_REPLICA );

		$userGroups = $this->userGroups;
		$userGroups[] = $this->getUser()->getName();

		array_walk( $userGroups, static function ( &$value ) use ( $dbr ) {
			$value = $dbr->addQuotes( $value );
		} );

		return $this->sqlReplace( 'EXISTS (SELECT 1 FROM `' . $this->escapedDBprefix . 'CIForms_submissions_groups` WHERE '
			. 'submission_id = `' . $this->escapedDBprefix . 'CIForms_submissions`.id AND '

			// $userGroups gives a "false positive" SecurityCheck-SQLInjection
			. 'usergroup IN(' . implode( ',', $userGroups ) . ') )', $raw );
	}

	/**
	 * @param string $submission_id
	 */
	private function downloadPdf( $submission_id ) {
		$dbr = CIForms::getDB( DB_REPLICA );
		$conds = [ 'id' => $submission_id ];

		if ( !$this->isSysop() ) {
			$conds[] = $this->permissionsCond( false );
		}

		$row = $dbr->selectRow(
			$this->sqlReplace( 'CIForms_submissions' ),
			'*',
			$conds,
			__METHOD__
		);

		$row = (array)$row;

		if ( !$row || $row === [ false ] || empty( $row['data'] ) ) {
			exit( $this->msg( 'ci-forms-manage-download-error' ) );
		}

		$data = json_decode( $row['data'], true );
		$file = ( new CIFormsSubmit )->createPDF( $data, $row['username'], $row['created_at'] );

		// userTimeAndDate
		$submission_date = htmlspecialchars(
			$this->getLanguage()->userDate(
				wfTimestamp( TS_MW, $row['created_at'] ),
				$this->getUser()
			)
		);

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Transfer-Encoding: Binary' );
		header( 'Content-disposition: attachment; filename="' . $row['title'] . ' - ' . $submission_date . '.pdf"' );

		ob_clean();

		exit( $file );
	}

	/**
	 * @param string $submission_id
	 * @param string $format
	 */
	private function export( $submission_id, $format ) {
		$dbr = CIForms::getDB( DB_REPLICA );
		$conds = [ 'id' => $submission_id ];

		if ( !$this->isSysop() ) {
			$conds[] = $this->permissionsCond( false );
		}

		$row = $dbr->selectRow(
			$this->sqlReplace( 'CIForms_submissions' ),
			'*',
			$conds,
			__METHOD__
		);

		$row = (array)$row;

		if ( !$row || $row === [ false ] || empty( $row['data'] ) ) {
			exit( $this->msg( 'ci-forms-manage-download-error' ) );
		}

		// get all submissions
		$this->page_id = $row['page_id'];
		$this->form_title = $row['title'];

		$res = $this->reallyDoQuery( false, false, false );
		$res = $this->mockupResults( $res, true );

		// this will create $this->headers
		$this->openList( 0 );

		if ( array_key_last( $this->headers ) == 'pdf' ) {
			array_pop( $this->headers );
		}

		$headers = array_map( static function ( $value ) {
			return html_entity_decode( $value );
		}, $this->headers );

		$headers_ = $headers;
		unset( $headers_['entry'], $headers_['username'], $headers_['submission_date'] );

		$data = [];
		$n = 0;
		foreach ( $res as $key => $row ) {
			$n++;
			$row = (array)$row;

			$values = [];
			array_walk( $headers_, static function ( $value, $key ) use( $row, &$values ) {
				$values[] = ( array_key_exists( $key, $row ) ? $row[$key] : "" );
			} );

			// php < 7.4
			$data[] = array_merge( [ $n, $row['username'] ], $values, [ $row['created_at'] ] );

			// php 7.4
			// $data[] = [ $n, $row['username'], ...$values,  $row['created_at'] ];
		}

		$submission_date = htmlspecialchars(
			$this->getLanguage()->userDate(
				wfTimestamp( TS_MW ),
				$this->getUser()
			)
		);

		$filename = $row['title'] . ' - ' . $submission_date;
		$headers = array_values( $headers );

		switch ( $format ) {
			case 'csv':
				$this->exportCsv( $headers, $data, $filename );
				break;

			case 'excel':
				$this->exportExcel( $headers, $data, $filename );
				break;

		}
	}

	/**
	 * @param array $headers
	 * @param array $data
	 * @param string $filename
	 */
	private function exportCsv( $headers, $data, $filename ) {
		ob_clean();

		$output = fopen( "php://output", 'w' );

		if ( $output === false ) {
			exit( "Can't open php://output" );
		}

		fputcsv( $output, $headers );

		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}

		header( 'Content-Type: application/csv' );
		header( 'Content-Transfer-Encoding: Binary' );
		header( 'Content-disposition: attachment; filename="' . $filename . '.csv"' );

		// ob_clean();
		fclose( $output ) || die( "Can't close php://output" );
		exit();
	}

	/**
	 * @see https://github.com/PHPOffice/PhpSpreadsheet/blob/master/samples/Table/01_Table.php
	 * @param array $headers
	 * @param array $data
	 * @param string $filename
	 */
	private function exportExcel( $headers, $data, $filename ) {
		if ( !class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			echo 'PhpOffice not installed';
			exit();
		}

		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$spreadsheet = new Spreadsheet();
		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$sheet = $spreadsheet->getActiveSheet();

		$setCellValue = static function ( $columnIndex, $row, $value ) use( $sheet ) {
			if ( method_exists( $sheet, 'setCellValueByColumnAndRow' ) ) {
				$sheet->setCellValueByColumnAndRow( $columnIndex, $row, $value );
			} else {
				$sheet->setCellValue( [ $columnIndex, $row ], $value );
			}
		};

		foreach ( $headers as $key => $value ) {
			// $columnIndex, $row, $value
			$setCellValue( $key + 1, 1, $value );
		}

		foreach ( $data as $key => $value ) {
			foreach ( $value as $k => $v ) {
				$setCellValue( $k + 1, ( $key + 1 + 1 ), $v );
			}
		}

		// $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter( $spreadsheet, 'Xlsx' );
		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-disposition: attachment; filename="' . $filename . '.xlsx"' );
		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$writer->save( 'php://output' );

		exit();
	}

	/**
	 * Generate (prev x| next x) (20|50|100...) type links for paging
	 *
	 * @param int $offset
	 * @param int $limit
	 * @param array $query Optional URL query parameter string
	 * @param bool $atend Optional param for specified if this is the last page
	 * @param string|bool $subpage Optional param for specifying subpage
	 * @return string
	 */
	protected function buildPrevNextNavigation(
		$offset,
		$limit,
		array $query = [],
		$atend = false,
		$subpage = false
	) {
		$title = $this->getPageTitle( $subpage );
		// ***edited
		$prevNext = new PrevNextNavigationRendererCIForms( $this, [ 'form_title' => $this->form_title, 'page_id' => $this->page_id ] );

		return $prevNext->buildPrevNextNavigation( $title, $offset, $limit, $query, $atend );
	}

	/**
	 * @return string Input form
	 */
	protected function showOptions() {
		$formDescriptor = [];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'ci-forms-manage-form-search-legend' )
			->setSubmitText( $this->msg( 'ci-forms-manage-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @param string $IDs
	 * @return void
	 */
	public static function deleteItems( $IDs ) {
		if ( !empty( $IDs ) ) {
			$dbw = CIForms::getDB( DB_PRIMARY );
			$tablename = 'CIForms_submissions';
			$IDs = array_map( static function ( $value ) use ( $dbw ) {
				return $dbw->addQuotes( $value );
			}, explode( ',', $IDs ) );
			$dbw->delete( $tablename, [ 'id IN (' . implode( ',', $IDs ) . ')' ], __METHOD__ );
		}
	}

	/**
	 * @return array
	 */
	protected function getUserGroups() {
		$user = $this->getUser();
		$UserGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		$user_groups = array_unique( array_merge(
			$UserGroupManager->getUserEffectiveGroups( $user ),
			$UserGroupManager->getUserImplicitGroups( $user )
		) );
		$key = array_search( '*', $user_groups );
		$user_groups[ $key ] = 'all';
		return $user_groups;
	}

	/**
	 * @return bool
	 */
	public function isCacheable() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function isExpensive() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function isSyndicated() {
		return false;
	}

	/**
	 * @param int|bool $limit Numerical limit or false for no limit
	 * @param int|bool $offset Numerical offset or false for no offset
	 * @param bool $DESC
	 * @return IResultWrapper
	 * @since 1.18
	 */
	public function reallyDoQuery( $limit, $offset = false, $DESC = true ) {
		$fname = static::class . '::reallyDoQuery';
		$dbr = $this->getRecacheDB();
		$query = $this->getQueryInfo();
		$order = $this->getOrderFields();

		// if ( $this->sortDescending() ) {
		if ( $DESC ) {
			foreach ( $order as &$field ) {
				$field .= ' DESC';
			}
		}

		if ( is_array( $query ) ) {
			$tables = isset( $query['tables'] ) ? (array)$query['tables'] : [];
			$fields = isset( $query['fields'] ) ? (array)$query['fields'] : [];
			$conds = isset( $query['conds'] ) ? (array)$query['conds'] : [];
			$options = isset( $query['options'] ) ? (array)$query['options'] : [];
			$join_conds = isset( $query['join_conds'] ) ? (array)$query['join_conds'] : [];

			if ( $order ) {
				$options['ORDER BY'] = $order;
			}

			if ( $limit !== false ) {
				$options['LIMIT'] = intval( $limit );
			}

			if ( $offset !== false ) {
				$options['OFFSET'] = intval( $offset );
			}

			$res = $dbr->select( $tables, $fields, $conds, $fname,
					$options, $join_conds
			);
		} else {
			// Old-fashioned raw SQL style, deprecated
			$sql = $this->getSQL();
			$sql .= ' ORDER BY ' . implode( ', ', $order );

			if ( $limit !== false ) {
				$sql = $dbr->limitResult( $sql, $limit, $offset );
			}

			// phpcs:ignore MediaWiki.Usage.DbrQueryUsage.DbrQueryFound
			$res = $dbr->query( $sql, $fname );
		}

		return $res;
	}

	/**
	 * @param IResultWrapper $res
	 * @param bool $export
	 * @return FakeResultWrapper
	 */
	protected function mockupResults( $res, $export = false ) {
		$dbr = CIForms::getDB( DB_PRIMARY );
		$valid_results = [];
		foreach ( $res as $key => $row ) {
			if ( !empty( $row->data ) ) {
				$data = json_decode( $row->data, true );
				$valid_results[] = array_merge( (array)$row, $this->parseFormData( $data, false, !$export ) );

				if ( !$export && empty( $row->shown ) ) {
					$date = date( 'Y-m-d H:i:s' );
					$update_result = $dbr->update( $this->sqlReplace( 'CIForms_submissions' ), [ 'shown' => $date ], [ 'id' => $row->id ], __METHOD__ );
				}
			}
		}

		return new Wikimedia\Rdbms\FakeResultWrapper( $valid_results );
	}

	/**
	 * @return string
	 */
	protected function getSQL() {
		$dbr = CIForms::getDB( DB_REPLICA );

		if ( empty( $this->form_title ) ) {
			$sql = 'SELECT MAX(id) AS id, page_id, title, MAX(created_at) AS last_submission_date, COUNT(*) AS submissions,
SUM(CASE WHEN shown IS NULL THEN 1 ELSE 0 END) AS new
FROM `' . $this->escapedDBprefix . 'CIForms_submissions' . '`
' . ( $this->isSysop() ? '' : ' WHERE ' . $this->permissionsCond( true ) ) .
'GROUP BY page_id, title
';

		} else {
			$sql = 'SELECT *
FROM `' . $this->escapedDBprefix . 'CIForms_submissions' . '`
WHERE page_id = ' . $dbr->addQuotes( $this->page_id ) . '
AND title = ' . $dbr->addQuotes( $this->form_title )
			. ( $this->isSysop() ? '' : ' AND ' . $this->permissionsCond( true ) );
		}

		return $this->sqlReplace( $sql, true );
	}

	/**
	 * @return array|bool
	 */
	public function getQueryInfo() {
		return false;
	}

	/**
	 * @param int $offset
	 * @return string
	 */
	protected function openList( $offset ) {
		$output = "\n<table class='mw-datatable ciforms-manage-pager-table'>\n";
		$table_header = [];

		if ( empty( $this->form_title ) ) {
			$i18n = [
				'page' => 'ci-forms-manage-pager-header-page',
				'title' => 'ci-forms-manage-pager-header-title',
				'submissions' => 'ci-forms-manage-pager-header-submissions',
				'last_submission_date' => 'ci-forms-manage-pager-header-last-submission-date',
				'action' => 'ci-forms-manage-pager-header-action',
			];

			$this->headers = $i18n;	// array_keys($i18n);

			foreach ( $i18n as $val ) {
				$table_header[] = '<th>' . $this->msg( $val )->text() . '</th>';
			}
		} else {
			// get the latest entry of the form to
			// create table headings ... this assumes
			// that the wanted data structure is reflected
			// by the latest (possible) edit of a form

			$dbr = CIForms::getDB( DB_REPLICA );
			$conds = [ 'page_id' => $this->page_id, 'title' => $this->form_title ];

			if ( !$this->isSysop() ) {
				$conds[] = $this->permissionsCond( false );
			}

			$row = $dbr->selectRow(
				$this->sqlReplace( 'CIForms_submissions' ),
				'*',
				$conds,
				__METHOD__,
				[ 'ORDER BY' => 'created_at DESC' ]
			);

			$row = (array)$row;

			if ( $row && $row !== [ false ] && !empty( $row['data'] ) ) {
				$data = json_decode( $row['data'], true );
				$headers = [];

				$headers['select'] = $this->msg( 'ci-forms-manage-pager-header-select' );
				$headers['entry'] = $this->msg( 'ci-forms-manage-pager-header-entry' )->text();
				$headers['username'] = $this->msg( 'ci-forms-manage-pager-header-username' )->text();

				// @todo, use a better solution rather than parsing the
				// row two times
				$keys = array_keys( $this->parseFormData( $data, false ) );
				$values = array_keys( $this->parseFormData( $data, true ) );
				$headers = array_merge( $headers, array_combine( $keys, $values ) );

				$headers['submission_date'] = $this->msg( 'ci-forms-manage-pager-header-submission_date' )->text();

				if ( class_exists( 'Dompdf\Dompdf' ) ) {
					$headers['pdf'] = $this->msg( 'ci-forms-manage-pager-header-pdf' )->text();
				}

				foreach ( $headers as $val ) {
					$table_header[] = '<th>' . $val . '</th>';
				}

				$this->headers = $headers;
			}
		}

		return $output . implode( $table_header );
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @param IDatabase|Wikimedia\Rdbms\IReadableDatabase $dbr
	 * @param IResultWrapper $res
	 * @param int $num
	 * @param int $offset
	 */
	protected function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		if ( $num > 0 ) {
			$html = [];
			if ( !$this->listoutput ) {
				$html[] = $this->openList( $offset );
			}

			// $res might contain the whole 1,000 rows, so we read up to
			// $num [should update this to use a Pager]
			for ( $i = 0; $i < $num && $row = $res->fetchObject(); $i++ ) {

				// ***edited
				$this->entry = ( $i + $offset + 1 );

				$line = $this->formatResult( $skin, $row );
				if ( $line ) {
					$html[] = $line;
				}
			}

			if ( !$this->listoutput ) {
				$html[] = $this->closeList();
			}

			# *** edited
			/*
			$html = $this->listoutput
				? $this->getContentLanguage()->listToText( $html )
				: implode( '', $html );
			*/
			$html = implode( '', $html );
			// @phan-suppress-next-line SecurityCheck-XSS
			$out->addHTML( $html );
		}
	}

	/**
	 * ResourceLoader modules that must be loaded to provide correct styling for this pager
	 *
	 * @return string[]
	 */
	public function getModuleStyles() {
		return [ 'mediawiki.pager.styles', 'oojs-ui.styles.icons-movement', 'oojs-ui.styles.icons-layout' ];
	}

	/**
	 * @return string
	 */
	protected function closeList() {
		return "</table>\n";
	}

	/**
	 * @param Skin $skin
	 * @param stdClass $result Result row
	 * @return string
	 */
	public function formatResult( $skin, $result ) {
		$result = (array)$result;
		// print_r($result);
		$output = [];

		foreach ( $this->headers as $key => $value ) {
			$formatted = null;

			if ( empty( $this->form_title ) ) {
				switch ( $key ) {
					case 'page':
						$title = Title::newFromID( $result['page_id'] ); // Title::makeTitleSafe( NS_MAIN, $row->page);

						if ( !$title ) {
							$title = Title::makeTitle( NS_MAIN, $result['title'] );
						}

						$linkRenderer = $this->getLinkRenderer();
						$formatted = $linkRenderer->makeLink( $title );
						break;
					case 'last_submission_date':
						$formatted = htmlspecialchars(
							$this->getLanguage()->userTimeAndDate(
								wfTimestamp( TS_MW, $result['last_submission_date'] ),
								$this->getUser()
							)
						);
						break;
					case 'submissions':
						$formatted = $result[$key];

						if ( !empty( $result['new'] ) ) {
							$formatted .= ' (' . $result['new'] . ' new)';
						}
						break;
					case 'action':
						$url = SpecialPage::getTitleFor( 'CIFormsManage' )->getLocalURL();
						$formatted = new OOUI\ButtonWidget(
							[
								'href' => wfAppendQuery( $url, 'form_title=' . urlencode( $result['title'] ) . '&page_id=' . $result['page_id'] ),
								'label' => $this->msg( 'ci-forms-manage-pager-button-open' )->text(),
								'infusable' => true,
								'flags' => [ 'progressive', 'primary' ]
							]
						// *** this uses ButtonMenuSelectWidget client-side
						// @see https://gerrit.wikimedia.org/r/plugins/gitiles/oojs/ui/+/refs/heads/master/demos/pages/widgets.js
						) . new OOUI\ButtonWidget(
							[
								"classes" => [ ( class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ? "ciforms-manage-button-export" : "" ) ],
								"icon" => ( class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ? 'menu' : null ),
								'href' => wfAppendQuery( $url, 'export=' . $result['id'] . '&format=csv' ),
								'label' => $this->msg( 'ci-forms-manage-pager-button-export' )->text(),
								'infusable' => true,
								'flags' => [ 'progressive', 'primary' ]
							]
						);
						break;
					default:
						$formatted = $result[$key];
				}
			} else {
				switch ( $key ) {
					case 'select':
						// @see MediaWiki\HTMLForm\Field\HTMLCheckField
						$formatted = new \OOUI\CheckboxInputWidget( [
							'classes' => [ 'ciforms-manage-button-select' ],
							'data' => [ 'id' => $result['id'] ],
							'infusable' => true
						] );
						break;
					case 'entry':
						$formatted = $this->entry;
						break;
					case 'username':
						$formatted = ( !empty( $result['username'] ) ? $result['username'] : null );
						break;
					case 'submission_date':
						$formatted = htmlspecialchars(
							$this->getLanguage()->userTimeAndDate(
								wfTimestamp( TS_MW, $result['created_at'] ),
								$this->getUser()
							)
						);
						break;
					case 'pdf':
						$url = SpecialPage::getTitleFor( 'CIFormsManage' )->getLocalURL();
						$formatted = new OOUI\ButtonWidget(
							[
								'href' => wfAppendQuery( $url, 'download=' . $result['id'] ),
								'label' => $this->msg( 'ci-forms-manage-pager-button-download-pdf' )->text(),
								'infusable' => true,
								'flags' => [ 'progressive', 'primary' ]
							]
						);
						break;
					default:
						if ( !empty( $result[$key] ) ) {
							$formatted = $result[$key];
						}
				}
			}
			$output[] = $formatted;
		}

		return '<tr>' . implode(
			array_map(
				static function ( $val ) {
					return "<td>$val</td>";
				},
				$output
			)
		) . '</tr>';
	}

	/**
	 * @param string $str
	 * @param int $length
	 * @return string
	 */
	private static function trimSectionTitle( $str, $length = 30 ) {
		$decoded = html_entity_decode( $str );
		if ( strlen( $decoded ) <= $length ) {
			return $str;
		}

		$str = substr( $decoded, 0, $length - 3 );
		return htmlentities( $str ) . '...';
	}

	/**
	 * @param array $arr
	 * @param bool $heading
	 * @return string|null
	 */
	private static function fallbackNonEmpty( $arr, $heading ) {
		if ( !$heading || ( array_key_exists( 'wgCIFormsSubmissionsTableForceInputNamesInHeading', $GLOBALS ) && $GLOBALS['wgCIFormsSubmissionsTableForceInputNamesInHeading'] ) ) {
			return array_pop( $arr );
		}

		$ret = null;
		while ( empty( $ret ) ) {
			$ret = array_shift( $arr );
		}
		return $ret;
	}

	/**
	 * @param array $data
	 * @param bool $heading
	 * @param bool $html
	 * @return array
	 */
	private function parseFormData( $data, $heading, $html = true ) {
		$array = [];
		$output = [];

		foreach ( $data['sections'] as $section ) {
			// *** this could be determined by a bug
			// now solved
			if ( empty( $section['type'] ) ) {
				continue;
			}

			switch ( $section['type'] ) {
				case 'inputs':
				case 'inputs responsive':
					foreach ( $section['items'] as $value ) {
						$label = $value['label'];
						$main_label = "";
						if ( $section['type'] == 'inputs responsive' ) {
							preg_match( "/^\s*([^\[\]]+)\s*(.+)\s*$/", $value['label'], $match );
							if ( count( $match ) === 2 ) {
								$main_label = $match[1];
								$label = $match[2];
							}
						}
						$i = 0;

						// alternatively use preg_split
						// @phan-suppress-next-line PhanPluginUseReturnValueInternalKnown
						preg_replace_callback(
							'/([^\[\]]*)\[([^\[\]]*)\]\s*(\*)?/',
							static function ( $matches ) use ( &$i, $value, &$array, $html, $main_label, $section, $heading ) {
								$label = $matches[1];
								[ $input_type, $placeholder, $input_options ] = CIForms::ci_form_parse_input_symbol( $matches[2] ) + [ null, null, null ];

								// @phan-suppress-next-line PhanSuspiciousValueComparison
								if ( $input_type === 'text' ) {
									$input_type = 'text input';
								}

								$fallbackLabel = self::fallbackNonEmpty( [ $label, $placeholder, $main_label, $input_type ], $heading );

								$array[] = [ $fallbackLabel, ( $value['inputs'][$i] ?? "" ) ];
								$i++;
							},
							$label
						);
					}
					break;
				case 'multiple choice':
					$value_ = [];

					foreach ( $section['items'] as $key => $value ) {
						if ( !empty( $value['selected'] ) ) {
							$value_[] = ( $key + 1 ) . ( !empty( $value['inputs'] ) ? ' (' . implode( ' &ndash; ', $value['inputs'] ) . ')' : '' );
						}
					}

					$fallbackLabel = self::fallbackNonEmpty( [ self::trimSectionTitle( $section['title'] ), $section['type'] ], $heading );

					$array[] = [ $fallbackLabel, implode( ( $html ? '<br />' : "\n" ), $value_ ) ];
					break;
				case 'cloze test':
					$value_ = [];
					foreach ( $section['items'] as $key => $value ) {
						$label = trim( $value['label'] );
						$example = ( $label[0] == '*' );

						// ignore the example line since
						// the numeration isn't handled correctly by
						// Dompdf using css counter-increment
						if ( $example ) {
							continue;
						}

						$value_[] = ( $html ? '<li>' : "" ) . ( !empty( $value['inputs'] ) ? implode( ' &ndash; ', $value['inputs'] ) : '' ) . ( $html ? '</li>' : "" );

					}
					$list_type_ordered = in_array( $section['list-style'], CIForms::$ordered_styles );
					$fallbackLabel = self::fallbackNonEmpty( [ self::trimSectionTitle( $section['title'] ), $section['type'] ], $heading );

					if ( $html ) {
						$array[] = [ $fallbackLabel, '<' . ( !$list_type_ordered ? 'ul' : 'ol' ) . ' style="list-style:' . $section['list-style'] . '">' . implode( $value_ ) . '</' . ( !$list_type_ordered ? 'ul' : 'ol' ) . '>' ];

					} else {
						$i = 0;
						$array[] = [ $fallbackLabel, implode( "\n", !$list_type_ordered ? $value_ : array_map( static function ( $value ) use ( &$i ) {
							return ( ++$i ) . ". " . $value;
						}, $value_ ) ) ];
					}

					break;
			}
		}

		// handle duplicated keys
		$increment = [];
		foreach ( $array as $key => $value ) {
			if ( !array_key_exists( $value[0], $increment ) ) {
				$increment[ $value[0] ] = 0;
			} else {
				$increment[ $value[0] ]++;
			}
		}

		$increment = array_filter( $increment );

		array_walk( $increment, static function ( &$value ) {
			$value = 0;
		} );

		foreach ( $array as $value ) {
			$output[ $value[0] . ( array_key_exists( $value[0], $increment ) ? '#' . ++$increment[ $value[0] ] : '' )  ] = ( $html ? addslashes( $value[1] ) : $value[1] );
		}

		return $output;
	}

	/**
	 * @return array
	 */
	public function getOrderFields() {
		return [ ( empty( $this->form_title ) ? 'last_submission_date' : 'created_at' ) ];
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'ciforms';
	}

}
