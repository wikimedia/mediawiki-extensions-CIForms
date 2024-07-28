<?php
/**
 * This file is part of the MediaWiki extension CIForms.
 *
 * Wiskiphere_Subpages is free software: you can redistribute it and/or modify
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
 * along with CIForms. If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2021-2024, https://wikisphere.org
 */

class PrevNextNavigationRendererCIForms {

	/** @var array */
	private $additional_query_parameters;

	/**
	 * @var MessageLocalizer
	 */
	private $messageLocalizer;

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param array $additional_query_parameters
	 */
	public function __construct( MessageLocalizer $messageLocalizer, $additional_query_parameters ) {
		// ***edited
		// parent::__construct( $messageLocalizer );
		$this->messageLocalizer = $messageLocalizer;
		$this->additional_query_parameters = $additional_query_parameters;
	}

// *** no edits, but cannot be removed because it calls
// numLink() below

	/**
	 * Generate (prev x| next x) (20|50|100...) type links for paging
	 *
	 * @param Title $title Title object to link
	 * @param int $offset
	 * @param int $limit
	 * @param array $query Optional URL query parameter string
	 * @param bool $atend Optional param for specified if this is the last page
	 * @return string
	 */
	public function buildPrevNextNavigation(
		Title $title,
		$offset,
		$limit,
		array $query = [],
		$atend = false
	) {
		# Make 'previous' link
		$prev = $this->messageLocalizer->msg( 'prevn' )
			->title( $title )
			->numParams( $limit )
			->text();

		if ( $offset > 0 ) {
			$plink = $this->numLink(
				$title,
				max( $offset - $limit, 0 ),
				$limit,
				$query,
				$prev,
				'prevn-title',
				'mw-prevlink'
			);
		} else {
			$plink = htmlspecialchars( $prev );
		}

		# Make 'next' link
		$next = $this->messageLocalizer->msg( 'nextn' )
			->title( $title )
			->numParams( $limit )
			->text();
		if ( $atend ) {
			$nlink = htmlspecialchars( $next );
		} else {
			$nlink = $this->numLink(
				$title,
				$offset + $limit,
				$limit,
				$query,
				$next,
				'nextn-title',
				'mw-nextlink'
			);
		}

		# Make links to set number of items per page
		$numLinks = [];
		// @phan-suppress-next-next-line PhanUndeclaredMethod
		// @fixme MessageLocalizer doesn't have a getLanguage() method!
		$lang = $this->messageLocalizer->getLanguage();
		foreach ( [ 20, 50, 100, 250, 500 ] as $num ) {
			$numLinks[] = $this->numLink(
				$title,
				$offset,
				$num,
				$query,
				$lang->formatNum( $num ),
				'shown-title',
				'mw-numlink'
			);
		}

		return $this->messageLocalizer->msg( 'viewprevnext' )
			->title( $title )
			->rawParams( $plink, $nlink, $lang->pipeList( $numLinks ) )
			->escaped();
	}

	/**
	 * Helper function for buildPrevNextNavigation() that generates links
	 *
	 * @param Title $title Title object to link
	 * @param int $offset
	 * @param int $limit
	 * @param array $query Extra query parameters
	 * @param string $link Text to use for the link; will be escaped
	 * @param string $tooltipMsg Name of the message to use as tooltip
	 * @param string $class Value of the "class" attribute of the link
	 * @return string HTML fragment
	 */
	private function numLink(
		Title $title,
		$offset,
		$limit,
		array $query,
		$link,
		$tooltipMsg,
		$class
	) {
		$query = [ 'limit' => $limit, 'offset' => $offset ] + $query;
		$tooltip = $this->messageLocalizer->msg( $tooltipMsg )
			->title( $title )
			->numParams( $limit )
			->text();

		// ***edited
		$query = array_merge( $this->additional_query_parameters, $query );

		return Html::element(
			'a',
			[
				'href' => $title->getLocalURL( $query ),
				'title' => $tooltip,
				'class' => $class,
			],
			$link
		);
	}

}
