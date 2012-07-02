<?php

class BatchResetEmbargo extends CMSBatchAction {
	function getActionTitle() {
		return _t('BatchResetEmbargo.ACTION_TITLE', 'Reset embargo date');
	}
	function getDoingText() {
		return _t('BatchResetEmbargo.DOING_TEXT', 'Resetting embargo date');
	}

	function run(SS_List $objs) {
		return $this->batchaction($objs, 'resetEmbargo',
			_t('BatchResetEmbargo.ACTIONED_PAGES', 'Reset embargo date on %d pages, %d failures'));
	}

	function applicablePages($ids) {
		return $this->applicablePagesHelper($ids, 'canChangeEmbargo', true, true);
	}
}
