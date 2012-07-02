<?php

class BatchSetExpiry extends CMSBatchAction {
	function getActionTitle() {
		return _t('BatchSetExpiry.ACTION_TITLE', 'Set expiry date');
	}
	function getDoingText() {
		return _t('BatchSetExpiry.DOING_TEXT', 'Setting expiry date');
	}

	function run(SS_List $objs) {
		$datefield = Object::create('DatetimeField', 'EmbargoDate_Batch');
		$datefield->setValue($_REQUEST['ExpiryDate_Batch']);
		
		$date = date('d/m/Y', strtotime($datefield->dataValue()));
		$time = date('h:i a', strtotime($datefield->dataValue()));
		return $this->batchaction($objs, 'setExpiry',
			_t('BatchSetExpiry.ACTIONED_PAGES', 'Set expiry date on %d pages, %d failures'),
		array($date, $time));
	}
	
	function getParameterFields() {
		return new FieldSet(Object::create('DatetimeField', 'ExpiryDate_Batch'));
	}
	
	function confirmationDialog($ids) {
		$pagesWithBacklinks = array();
		foreach($ids as $id) {
			$page = DataObject::get_by_id('SiteTree', $id);
			if ($page->DependentPagesCount(false)) $pagesWithBacklinks[] = $page->AbsoluteLink();
		}
		
		return array(
			'alert' => count($pagesWithBacklinks) ? true : false,
			'content' => 'The following pages will create broken links when they expire:'."\n\n".
							join("\n", $pagesWithBacklinks)."\n\nProceed?"
		);
	}

	function applicablePages($ids) {
		return $this->applicablePagesHelper($ids, 'canChangeExpiry', true, true);
	}
}
