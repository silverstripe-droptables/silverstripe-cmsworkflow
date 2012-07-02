<?php

class BatchResetExpiry extends CMSBatchAction {
	function getActionTitle() {
		return _t('BatchResetExpiry.ACTION_TITLE', 'Reset expiry date');
	}
	function getDoingText() {
		return _t('BatchResetExpiry.DOING_TEXT', 'Resetting expiry date');
	}

	function run(SS_List $objs) {
		return $this->batchaction($objs, 'resetExpiry',
			_t('BatchResetExpiry.ACTIONED_PAGES', 'Reset expiry date on %d pages, %d failures'));
	}

	function applicablePages($ids) {
		return $this->applicablePagesHelper($ids, 'canChangeExpiry', true, true);
	}
}
