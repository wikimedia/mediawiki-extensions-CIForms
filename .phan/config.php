<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'][] = 'PhanImpossibleCondition';
$cfg['suppress_issue_types'][] = 'PhanPluginDuplicateAdjacentStatement';
// PhanPluginNeverReturnMethod issue is only happening in CIFormsManage::downloadPdf,
// can't be suppressed inline
$cfg['suppress_issue_types'][] = 'PhanPluginNeverReturnMethod';
$cfg['suppress_issue_types'][] = 'PhanPossiblyUndeclaredVariable';
$cfg['suppress_issue_types'][] = 'PhanTypeMismatchArgumentInternal';

// Ignored to allow upgrading Phan, to be fixed later.
$cfg['suppress_issue_types'][] = 'MediaWikiNoEmptyIfDefined';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'vendor/dompdf/dompdf',
		'vendor/phpmailer/phpmailer'
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'vendor/dompdf/dompdf',
		'vendor/phpmailer/phpmailer'
	]
);

return $cfg;
