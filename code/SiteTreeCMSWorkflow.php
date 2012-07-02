<?php
/**
 * Extension to SiteTree for CMS Workflow support.
 * 
 * @todo Currently a publication/deletion approval is implicit by the "save and publish" and "delete from live" actions.
 * This also means that CMS editors not assigned to this workflow, but with publish rights on the page can (unknowingly)
 * end a workflow request. These assumptions are codified in {@link onAfterPublish()} and {@link onAfterDelete()}
 *
 * @package cmsworkflow
 */
class SiteTreeCMSWorkflow extends DataExtension {
	
	/**
	 * A registry of all allowed request classes.
	 * 
	 * @var Array $allowed_request_classes 
	 */
	protected static $allowed_request_classes = array();
	
	/**
	 * @param string $requestClass
	 * @return bool
	 */
	public static function register_request($requestClass) {
		if(class_exists($requestClass) == false)
			return false;

		if(is_subclass_of($requestClass, 'WorkflowRequest') == false)
			return false;

		if(in_array($requestClass, self::$allowed_request_classes) == false) {
			array_push(self::$allowed_request_classes, $requestClass);
		}

		return true;
	}
	
	/**
	 * @param string $requestClass
	 * @return bool Returns TRUE on success, FALSE otherwise.
	 */
	public static function unregister_request($requestClass) {
		if(in_array($requestClass, self::$authenticators)) {
			unset(self::$allowed_request_classes[array_search($requestClass, self::$allowed_request_classes)]);
		}
	}
	
	function extraStatics($class = null, $extension = null) {
		return array(
			'db' => array(
				"ExpiryDate" => "SS_Datetime",
			),
			'has_one' => array(
				'LatestCompletedWorkflowRequest' => 'WorkflowRequest'
			),
			'has_many' => array(
				// has_one OpenWorkflowRequest is implemented as custom getter
				'WorkflowRequests' => 'WorkflowRequest'
			),
		);
	}
	
	/**
	 * Set the embargo date for this SiteTree object
	 * this actually gets written to the current open
	 * workflow request, not the SiteTree object.
	 *
	 * @param string $date dd/mm/yyyy
	 * @param string $time 
	 * @return boolean
	 */
	function setEmbargo($date, $time = null) {
		if (!$time) {
			// split $date by space to get two time components, which happens on form submission
			$parts = explode(" ", $date);
			$date = $parts[0];
			$time = isset($parts[1]) ? $parts[1] : "00:00:00";
		}
		if ($wf = $this->openWorkflowRequest()) {
			if ($wf->EmbargoField()) {
				if (count(explode('/', $date)) != 3) return false;
				list($day, $month, $year) = explode('/', $date);
				$timestamp = strtotime("$year-$month-$day $time");
				if ($timestamp && $timestamp > SS_Datetime::now()->getValue()) {
					$wf->EmbargoDate = $timestamp;
					$wf->write();
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * Set the expiry date for this SiteTree object
	 *
	 * @param string $date dd/mm/yyyy
	 * @param string $time 
	 * @return boolean
	 */
	function setExpiry($date, $time = null) {
		if (!$time) {
			// split $date by space to get two time components, which happens on form submission
			$parts = explode(" ", $date);
			$date = $parts[0];
			$time = isset($parts[1]) ? $parts[1] : "00:00:00";
		}
		if ($wf = $this->openWorkflowRequest()) {
			if ($wf->ExpiryField()) {
				if (count(explode('/', $date)) != 3) return false;
				list($day, $month, $year) = explode('/', $date);
				$timestamp = strtotime("$year-$month-$day $time");
				if ($timestamp) {
					if ($wf->EmbargoDate && $timestamp < strtotime($wf->EmbargoDate)) return false;
					$this->owner->ExpiryDate = $timestamp;
					$this->owner->write();
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * Reset the embargo date
	 *
	 * @return boolean
	 */
	function resetEmbargo() {
		if ($wf = $this->openWorkflowRequest()) {
			$wf->EmbargoDate = null;
			$wf->write();
			return true;
		}
		return false;
	}
	
	/**
	 * Reset the expiry date
	 *
	 * @return boolean
	 */
	function resetExpiry() {
		if ($wf = $this->openWorkflowRequest()) {
			$this->owner->ExpiryDate = null;
			$this->owner->write();
			return true;
		}
		return false;
	}
	
	/**
	 * Can we change the embargo date - only if there is an open workflow request
	 */
	function canChangeEmbargo() {
		return $this->openWorkflowRequest() && $this->openWorkflowRequest()->EmbargoField();
	}

	/**
	 * Can we change the embargo date - only if there is an open workflow request
	 */
	function canChangeExpiry() {
		return $this->openWorkflowRequest() && $this->openWorkflowRequest()->ExpiryField();
	}
	
	/**
	 * Cancel a expiry on the live end directly
	 */
	public function cancelexpiry() {
		if(!$this->owner->canApprove()) return false;
		
		DB::query('UPDATE "SiteTree_Live" SET "ExpiryDate" = \'0000-00-00 00:00:00\' WHERE "ID" = '.$this->owner->ID);
	}
	
	function updateCMSFields(FieldList $fields) {
		if($wf = $this->openWorkflowRequest()) {
			$fields->fieldByName('Root')->insertBefore(new Tab("Workflow",
				new LiteralField("WorkflowInfo", $this->owner->renderWith("SiteTreeCMSWorkflow_workflowtab"))
			), "Content");
		}
		
		// Check if there is an expiry date...
		$liveVersion = Versioned::get_one_by_stage('SiteTree', 'Live', "\"SiteTree_Live\".\"ID\" = {$this->owner->ID}");
		if ($liveVersion && $liveVersion->ExpiryDate != null && $liveVersion->ExpiryDate != '0000-00-00 00:00:00') {
			// TODO Remove TZDateTimeField dependency
			if (class_exists('TZDateTimeField')) {
				$tzField = new TZDateTimeField('ExpiryDate', 'Expiry Date', $liveVersion->ExpiryDate);
				$zones = $tzField->getTimezones();
				$fields->addFieldsToTab('Root.Expiry', array(
					new LiteralField(
						'ExpiryWarning', 
						sprintf(
							'<p>This page is scheduled to expire at %s, %s time. <a href="%s" target="_blank">View site on date</a></p>',
							DBField::create('SS_Datetime', $tzField->dataValue())->Nice24(),
							@$zones[$tzField->getConfig('usertimezone')],
							$this->ViewExpiredLink()
						)
					)
				));
			} else {
				$tzfield = Object::create('DateTimeField', 'ExpiryDate', 'Expiry Date', $liveVersion->ExpiryDate);
				$datetime = $liveVersion->dbObject('ExpiryDate');
				$fields->addFieldsToTab('Root.Expiry', array(
					new LiteralField(
						'ExpiryWarning', 
						sprintf(
							'<p>This page is scheduled to expire at %s, on %s <a href="%s" target="_blank">View site on date</a></p>',
							$datetime->Time(),
							$datetime->Long(),
							$this->ViewExpiredLink()
						)
					)
				));
			}
			if ($this->owner->BackLinkTracking() && $this->owner->BackLinkTracking()->Count() > 0) {
				$fields->addFieldsToTab('Root.Expiry', array(
					new HeaderField("Please check these pages", 2),
					new LiteralField('ExpiryBacklinkWarning', "<p>This page is scheduled to expire, but the following pages link to it.</p>"),
					$this->BacklinkTable()
				));
			}
		}
		
		$fields->addFieldsToTab('Root.WorkflowArchive', $this->getWorkflowCMSFields());
	}
	
	public function ViewExpiredLink() {
		return $this->owner->AbsoluteLink() . '?futureDate=' . $this->owner->dbObject('ExpiryDate')->URLDatetime();
	}
	
	function BacklinkTable() {
		$dependentColumns = array(
			'Title' => 'Title',
			'Subsite.Title' => 'Subsite',
			'AbsoluteLink' => 'URL',
			'DependentLinkType' => 'Link type',
		);
		if(!class_exists('Subsite')) unset($dependentColumns['Subsite.Title']);
		
		$dependentNote = new LiteralField('DependentNote', '<p>' . _t('SiteTree.DEPENDENT_NOTE', 'The following pages depend on this page. This includes virtual pages, redirector pages, and pages with content links.') . '</p>');
		$dependentTable = new TableListField(
			'DependentPagesOnExpiry',
			'SiteTree',
			$dependentColumns
		);
		$dependentTable->setCustomSourceItems($this->owner->DependentPages(false));
		$dependentTable->setFieldFormatting(array(
			'Title' => '<a href=\"admin/show/$ID\">$Title</a>',
			'AbsoluteLink' => '<a href=\"$value\">$value</a>',
		));
		$dependentTable->setPermissions(array(
			'show',
			'export'
		));
		
		return $dependentTable;
	}
	
	/**
	 * @return FieldSet
	 */
	public function getWorkflowCMSFields() {
		$fields = new FieldList();
		
		$diffLinkTitle = _t('SiteTreeCMSWorkflow.DIFFERENCESLINK', 'Show differences to live');

		// list all closed requests
		$fields->push(new HeaderField(
			'WorkflowClosedRequestsHeader', 
			_t('SiteTreeCMSWorkflow.CLOSEDREQUESTSHEADER', 'Closed Requests')
		));
		$closedRequests = $this->ClosedWorkflowRequests();
		$closedRequestsField = new GridField('ClosedWorkflowRequests', 'Closed workflow requests', $closedRequests);

		/*
		$closedRequestsTF = new ComplexTableField(
			$this,
			'ClosedWorkflowRequests',
			'WorkflowRequest',
			array(
				'Created' => singleton('WorkflowRequest')->fieldLabel('Created'), 
				'StatusDescription' => singleton('WorkflowRequest')->fieldLabel('Status'),
				'Author.Title' => singleton('WorkflowRequest')->fieldLabel('Author'),
				'DiffLinkToLastPublished' => _t('SiteTreeCMSWorkflow.DIFFERENCESCOLUMN', 'Differences'),
			)
		);
		$closedRequestsTF->setPermissions(array('show'));
		$closedRequestsTF->setFieldFormatting(array(
			"DiffLinkToLastPublished" => '<a href=\"$value\" target=\"_blank\" class=\"externallink\">' . $diffLinkTitle . '</a>'
		));
		$closedRequestsTF->setFieldCasting(array(
			'Created' => 'SS_Datetime->Full'
		));
		$closedRequestsTF->setCustomSourceItems($closedRequests);
		 */
		$fields->push($closedRequestsField);

		return $fields;
	}
	
	/**
	 * Normal authors (without publication permission) can perform certain actions on a page,
	 * e.g. "save" and "delete from draft". Other permissions like "publish" or "delete from live"
	 * are hidden based on the {@link SiteTree->canPublish()} permission, and replaced
	 * with triggers for requesting these actions ("request publication" and "request deletion").
	 *
	 * @param FieldSet $actions
	 */
	function updateCMSActions(FieldList $actions) {
		if(self::$allowed_request_classes) foreach(self::$allowed_request_classes as $class) {
			// @todo Workaround: calling static method as instance method to avoid eval()
			singleton($class)->update_cms_actions($actions, $this->owner);
		}
	}
	
	/**
	 * Returns actions for the worfklow tab.
	 * @todo This is a pretty clear example of user-interface logic baked into the model.  We 
	 * should solve this at a Sapphire-framework level, somehow.
	 */
	public function WorkflowActions() {
		$actions = $this->openWorkflowRequest()->WorkflowActions();
		$output = new DataObjectSet();
		foreach($actions as $code => $title) {
			$output->push(new ArrayData(array(
				'Action' => "action_$code",
				'Title' => Convert::raw2xml($title),
			)));			
		}
		return $output;
	}

	/**
	 * Return a DataObjectSet of Closed workflow requests.
	 * 
	 * @return DataObjectSet Set of WorkflowRequest objects
	 */
	public function ClosedWorkflowRequests($filter = "", $sort = "Created DESC", $join = "", $limit = "") {
		$this->componentCache = array();
		
		if($filter) $filter .= ' AND ';
		$filter .= "\"Status\" IN ('Completed','Denied')";
		return $this->owner->getComponents(
			'WorkflowRequests',
			$filter,
			$sort,
			$join,
			$limit
		);
	}

	public function openWorkflowRequest($workflowClass = 'WorkflowRequest') {
		if(is_subclass_of($workflowClass, 'WorkflowRequest') || $workflowClass == 'WorkflowRequest') {
			if((int)$this->owner->ID) {
				$wf = $this->owner->getOpenRequest($workflowClass);
				if($wf) return $wf;
				else return null;
			}
		} else {
			user_error("SiteTreeCMSWorkflow::openWorkflowRequest(): Bad workflow class '$workflowClass'", E_USER_WARNING);
		}
	}
	
	/**
	 * @param $workflowClass The class of the workflow object to create
	 * @param $notify Should I sent out emails notifying people of workflow activities?
	 */
	public function openOrNewWorkflowRequest($workflowClass, $notify = true) {
		if($wf = $this->openWorkflowRequest($workflowClass)) {
			return $wf;
		} else if(is_subclass_of($workflowClass, 'WorkflowRequest')) {
			// TODO: How to avoid eval() here? (Tom R:) below should do it.
			// return call_user_func(array($workflowClass, 'create_for_page'), $this->owner, null, null, $notify);
			return eval("return $workflowClass::create_for_page(\$this->owner, null, null, \$notify);");
		} else {
			user_error("SiteTreeCMSWorkflow::openOrNewWorkflowRequest(): Bad workflow class '$workflowClass'", E_USER_WARNING);
		}
	}
	
	function LastEditedBy() {
		$latestVersion = Versioned::get_latest_version('SiteTree', $this->owner->ID);
		if (!$latestVersion || !$latestVersion->AuthorID) return;
		
		return DataObject::get_by_id('Member', $latestVersion->AuthorID);
	}
	
	// Event handlers for the built-in actions will tidy up any open workflow that's leftover.
	// If a workflow action is calling publish/delete/etc, the workflow object should be cleaned up
	// *before* the action is called.
	
	/**
	 * After publishing remove from the report of items needing publication 
	 */
	function onAfterPublish() {
		if($wf = $this->openWorkflowRequest()) {
			if(get_class($wf) == 'WorkflowPublicationRequest') $wf->approve(_t('SiteTreeCMSWorkflow.AUTO_APPROVED', "(automatically approved)"));
			else $wf->deny(_t("SiteTreeCMSWorkflow.AUTO_DENIED_PUBLISHED", "(automatically denied when the page was published)"));
		}
		
		if($this->owner->ReviewPeriodDays) {
			$this->owner->NextReviewDate = date('Y-m-d', strtotime('+' . $this->owner->ReviewPeriodDays . ' days'));
			$this->owner->writeWithoutVersion();
		}
	}
	
	/**
	 * 
	 */
	function onBeforeDelete() {
		if($wf = $this->openWorkflowRequest()) {
			if(get_class($wf) == 'WorkflowDeletionRequest') $wf->approve(_t('SiteTreeCMSWorkflow.AUTO_APPROVED', "(automatically approved)"));
			else $wf->deny(_t("SiteTreeCMSWorkflow.AUTO_DENIED_DELETED", "(automatically denied when the page was deleted)"));
		}
	}
	
	function onAfterRevertToLive() {
		if($wf = $this->openWorkflowRequest()) {
			$wf->deny(_t('SiteTreeCMSWorkflow.AUTO_DENIED', "(automatically denied)"));
		}
	}
	
	function onBeforeDuplicate() {
		// Explicitly set expiry to null, it shouldn't persist in copies
		$this->owner->ExpiryDate = null;
	}

	function providePermissions() {
		return array(
			"EDIT_CONTENT_REVIEW_FIELDS" => "Set content owners and review dates",
		);
	}
}
?>
