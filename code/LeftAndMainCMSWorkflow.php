<?php
class LeftAndMainCMSWorkflow extends LeftAndMainDecorator {
	private static $promptAdminForComments = true;
	
	public static $allowed_actions = array(
		'cms_requestpublication',
		'cms_requestdeletefromlive',
		'cms_denypublication',
		'cms_denydeletion',
		'cms_setembargoexpiry',
	);

	public static function setPromptAdminForComments($enable) {
		self::$promptAdminForComments = $enable;
	}

	function cms_setembargoexpiry($data) {
		$wfRequest = DataObject::get_by_id('WorkflowRequest', $data['wfRequest']);
		if ($wfRequest) {
			if (!$wfRequest->CanChangeEmbargoExpiry()) {
				$result = array(
					'status' => 'failed',
					'message' => 'you cannot change the embargo/expiry dates at this time'
				);
			} else {
				if (isset($data['ResetEmbargo'])) {
					$wfRequest->EmbargoDate = null;
					$wfRequest->write();
					$result = array(
						'status' => 'success'
					);
				} else if (isset($data['ResetExpiry'])) {
					$wfRequest->Page()->ExpiryDate = null;
					$wfRequest->Page()->write();
					$result = array(
						'status' => 'success'
					);
				} else {
					$expiryTimestamp = $embargoTimestamp = null;
					if (isset($data['EmbargoDate']) && isset($data['EmbargoTime'])) {
						if (count(explode('/', $data['EmbargoDate'])) >= 3) {
							list($day, $month, $year) = explode('/', $data['EmbargoDate']);
							$embargoTimestamp = strtotime("$year-$month-$day {$data['EmbargoTime']}");
							$wfRequest->EmbargoDate = $embargoTimestamp;
						}
					}
					
					if (isset($data['ExpiryDate']) && isset($data['ExpiryTime'])) {
						if (count(explode('/', $data['ExpiryDate'])) >= 3) {
							list($day, $month, $year) = explode('/', $data['ExpiryDate']);
							$expiryTimestamp = strtotime("$year-$month-$day {$data['ExpiryTime']}");
							$wfRequest->Page()->ExpiryDate = $expiryTimestamp;
						}
					}
					
					$wfRequest->Page()->ExpiryDate = is_numeric($wfRequest->Page()->ExpiryDate) ? $wfRequest->Page()->ExpiryDate : strtotime($wfRequest->Page()->ExpiryDate);
					$wfRequest->EmbargoDate = is_numeric($wfRequest->EmbargoDate) ? $wfRequest->EmbargoDate : strtotime($wfRequest->EmbargoDate);
					
					// Validation time
					$error = false;
					if (isset($data['EmbargoDate']) && !$embargoTimestamp) {
						$error = "Embargo date is not valid";
					} else if (isset($data['ExpiryDate']) && !$expiryTimestamp) {
						$error = "Expiry date is not valid";
					} else if (isset($data['EmbargoDate']) && $wfRequest->EmbargoDate < time()) {
						$error = "Embargo date must be AFTER the current server time";
					} else if (isset($data['ExpiryDate']) && $wfRequest->Page()->ExpiryDate < time()) {
						$error = "Expiry date must be AFTER the current server time";
					} else if ($wfRequest->EmbargoDate && $wfRequest->Page()->ExpiryDate && $wfRequest->EmbargoDate > $wfRequest->Page()->ExpiryDate) {
						$error = "Embargo date must be BEFORE the expiry date";
					} else {
						if ($embargoTimestamp && $wfRequest->EmbargoField()) $wfRequest->write();
						if ($expiryTimestamp && $wfRequest->ExpiryField()) $wfRequest->Page()->write();
					
						$expiryTimestamp = $wfRequest->getExpiryDate();
						$embargoTimestamp = $wfRequest->getEmbargoDate();
						if (!is_numeric($expiryTimestamp)) $expiryTimestamp = strtotime($expiryTimestamp);
						if (!is_numeric($embargoTimestamp)) $embargoTimestamp = strtotime($embargoTimestamp);
						
						$result = array(
							'status' => 'success',
							'message' => array(
								'embargo' => $embargoTimestamp ? date('Y-m-d H:i:s', $embargoTimestamp) : null,
								'expiry' => $expiryTimestamp ? date('Y-m-d H:i:s', $expiryTimestamp) : null
							)
						);
					}
					
					if ($error) {
						$result = array(
							'status' => 'failed',
							'message' => $error
						);
					}
				}
			}
		} else {
			$result = array(
				'status' => 'failed',
				'message' => 'workflow request not found'
			);
		}
		return Convert::array2json($result);
	}
	
	function init() {
		// We need to make sure these CMSMain scripts are included first
		Requirements::javascript('cms/javascript/CMSMain.js');
		Requirements::javascript('cms/javascript/CMSMain_left.js');
		Requirements::javascript('cms/javascript/CMSMain_right.js');

		CMSBatchActionHandler::register('batchCmsWorkflowSetEmbargo', 'BatchSetEmbargo');
		CMSBatchActionHandler::register('batchCmsWorkflowSetExpiry', 'BatchSetExpiry');
		CMSBatchActionHandler::register('batchCmsWorkflowResetEmbargo', 'BatchResetEmbargo');
		CMSBatchActionHandler::register('batchCmsWorkflowResetExpiry', 'BatchResetExpiry');
		
		Requirements::javascript('cmsworkflow/javascript/LeftAndMainCMSWorkflow.js');

		Requirements::customScript("CMSWorkflow.setOption('noPromptForAdmin', " . Convert::raw2json(!self::$promptAdminForComments) . ')');
		RSSFeed::linkToFeed(Director::absoluteURL('admin/cms/changes.rss'), 'All content changes');
	}
	
	// Request
	
	/**
	 * Handler for the CMS button
	 */
	public function cms_requestpublication($data, $form) {
		return $this->workflowAction('WorkflowPublicationRequest', 'request', $data['ID'], $data['WorkflowComment']);
	}
	
	public function cms_requestdeletefromlive($data, $form) {
		return $this->workflowAction('WorkflowDeletionRequest', 'request', $data['ID'], $data['WorkflowComment']);
	}

	// Approve
	public function cms_approve($data, $form) {
		return $this->workflowAction('WorkflowRequest', 'approve', $data['ID'], $data['WorkflowComment']);
	}
	
	// Cancel expiry
	public function cms_cancelexpiry($data, $form) {
		$id = Convert::raw2sql($data['ID']);
		$page = Versioned::get_one_by_stage('SiteTree', 'Live', "SiteTree_Live.ID = '$id'");
		if ($page) $page->cancelexpiry();
		FormResponse::get_page($data['ID']);
		FormResponse::status_message(_t('SiteTreeCMSWorkflow.EXPIRYCANCELLED', 'Expiry cancelled.'), 'good');
		return FormResponse::respond();
	}
	
	/**
	 * When a page is saved, we need to check if there is an in-progress
	 * workflow request, and if applicable, set it back to AwaitingApproval
	 */
	public function onAfterSave($record) {
		if($record->hasMethod('openWorkflowRequest') && $wf = $record->openWorkflowRequest()) {
			if ($wf->Status != 'AwaitingApproval' && $wf->Status != 'AwaitingEdit') {
				$wf->request("Page was resaved, automatically set workflow request back to awaiting approval", null, false);
				FormResponse::add("$('Form_EditForm').getPageFromServer($record->ID);");
			}
		}
	}
	
	public function onBeforeRollback($pageID) {
		$record = DataObject::get_by_id('Page', $pageID);
		if($record && $record->hasMethod('openWorkflowRequest') && $wf = $record->openWorkflowRequest()) {
			$wf->cancel('Draft changes were cancelled, automatically closed workflow request');
		}
	}
	
	public function cms_publishwithcomment($urlParams, $form) {
		$className = 'SiteTree';
		$result = '';

		$SQL_id = Convert::raw2sql($_REQUEST['ID']);
		if(substr($SQL_id,0,3) != 'new') {
			$record = DataObject::get_one($className, "\"$className\".\"ID\" = {$SQL_id}");
			if($record && !$record->canEdit()) return Security::permissionFailure($this);
		} else {
			if(!singleton($this->stat('tree_class'))->canCreate()) return Security::permissionFailure($this);
			$record = $this->getNewItem($SQL_id, false);
		}

		// We don't want to save a new version if there are no changes
		$dataFields_new = $form->Fields()->dataFields();
		$dataFields_old = $record->getAllFields();
		$changed = false;
		$hasNonRecordFields = false;
		foreach($dataFields_new as $datafield) {
			// if the form has fields not belonging to the record
			if(!isset($dataFields_old[$datafield->Name()])) {
				$hasNonRecordFields = true;
			}
			// if field-values have changed
			if(!isset($dataFields_old[$datafield->Name()]) || $dataFields_old[$datafield->Name()] != $datafield->dataValue()) {
				$changed = true;
			}
		}

		if(!$changed && !$hasNonRecordFields) {
			// Tell the user we have saved even though we haven't, as not to confuse them
			if(is_a($record, "Page")) {
				$record->Status = "Saved (update)";
			}
			FormResponse::status_message(_t('LeftAndMain.SAVEDUP',"Saved"), "good");
			FormResponse::update_status($record->Status);
			return FormResponse::respond();
		}

		$form->dataFieldByName('ID')->Value = 0;

		if(isset($urlParams['Sort']) && is_numeric($urlParams['Sort'])) {
			$record->Sort = $urlParams['Sort'];
		}

		// HACK: This should be turned into something more general
		$originalClass = $record->ClassName;
		$originalStatus = $record->Status;
		$originalParentID = $record->ParentID;

		$record->HasBrokenLink = 0;
		$record->HasBrokenFile = 0;

		$record->writeWithoutVersion();

		// HACK: This should be turned into something more general
		$originalURLSegment = $record->URLSegment;

		$form->saveInto($record, true);

		if(is_a($record, "Page")) {
			$record->Status = ($record->Status == "New page" || $record->Status == "Saved (new)") ? "Saved (new)" : "Saved (update)";
		}

		if(Director::is_ajax()) {
			if($SQL_id != $record->ID) {
				FormResponse::add("$('sitetree').setNodeIdx(\"{$SQL_id}\", \"$record->ID\");");
				FormResponse::add("$('Form_EditForm').elements.ID.value = \"$record->ID\";");
			}

			if($added = DataObjectLog::getAdded('SiteTree')) {
				foreach($added as $page) {
					if($page->ID != $record->ID) $result .= $this->addTreeNodeJS($page);
				}
			}
			if($deleted = DataObjectLog::getDeleted('SiteTree')) {
				foreach($deleted as $page) {
					if($page->ID != $record->ID) $result .= $this->deleteTreeNodeJS($page);
				}
			}
			if($changed = DataObjectLog::getChanged('SiteTree')) {
				foreach($changed as $page) {
					if($page->ID != $record->ID) {
						$title = Convert::raw2js($page->TreeTitle());
						FormResponse::add("$('sitetree').setNodeTitle($page->ID, \"$title\");");
					}
				}
			}

			$message = _t('LeftAndMain.SAVEDUP');

			// Update the class instance if necessary
			if($originalClass != $record->ClassName) {
				$newClassName = $record->ClassName;
				// The records originally saved attribute was overwritten by $form->saveInto($record) before.
				// This is necessary for newClassInstance() to work as expected, and trigger change detection
				// on the ClassName attribute
				$record->setClassName($originalClass);
				// Replace $record with a new instance
				$record = $record->newClassInstance($newClassName);
				
				// update the tree icon
				FormResponse::add("if(\$('sitetree').setNodeIcon) \$('sitetree').setNodeIcon($record->ID, '$originalClass', '$record->ClassName');");
			}

			// HACK: This should be turned into somethign more general
			if( ($record->class == 'VirtualPage' && $originalURLSegment != $record->URLSegment) ||
				($originalClass != $record->ClassName) || LeftAndMain::$ForceReload == true) {
				FormResponse::add("$('Form_EditForm').getPageFromServer($record->ID);");
			}

			// After reloading action
			if($originalStatus != $record->Status) {
				$message .= sprintf(_t('LeftAndMain.STATUSTO',"  Status changed to '%s'"),$record->Status);
			}
			
			if($originalParentID != $record->ParentID) {
				FormResponse::add("if(\$('sitetree').setNodeParentID) \$('sitetree').setNodeParentID($record->ID, $record->ParentID);");
			}

			$record->write();
			
			// if changed to a single_instance_only page type
			if ($record->stat('single_instance_only')) {
				FormResponse::add("jQuery('#sitetree li.{$record->ClassName}').addClass('{$record->stat('single_instance_only_css_class')}');");
				FormResponse::add($this->hideSingleInstanceOnlyFromCreateFieldJS($record));
			}
			else {
				FormResponse::add("jQuery('#sitetree li.{$record->ClassName}').removeClass('{$record->stat('single_instance_only_css_class')}');");
			}
			// if chnaged from a single_instance_only page type
			$sampleOriginalClassObject = new $originalClass();
			if($sampleOriginalClassObject->stat('single_instance_only')) {
				FormResponse::add($this->showSingleInstanceOnlyInCreateFieldJS($sampleOriginalClassObject));
			}
			
			if( ($record->class != 'VirtualPage') && $originalURLSegment != $record->URLSegment) {
				$message .= sprintf(_t('LeftAndMain.CHANGEDURL',"  Changed URL to '%s'"),$record->URLSegment);
				FormResponse::add("\$('Form_EditForm').elements.URLSegment.value = \"$record->URLSegment\";");
				FormResponse::add("\$('Form_EditForm_StageURLSegment').value = \"{$record->URLSegment}\";");
			}

			// Update classname with original and get new instance (see above for explanation)
			$record->setClassName($originalClass);
			$publishedRecord = $record->newClassInstance($record->ClassName);
			
			return $this->workflowAction('WorkflowPublicationRequest', 'saveAndPublish', $urlParams['ID'], $urlParams['WorkflowComment']);
			
			

			// return $this->owner->tellBrowserAboutPublicationChange(
			// 	$publishedRecord, 
			// 	sprintf(
			// 		_t(
			// 			'LeftAndMain.STATUSPUBLISHEDSUCCESS', 
			// 			"Published '%s' successfully",
			// 			PR_MEDIUM,
			// 			'Status message after publishing a page, showing the page title'
			// 		),
			// 		$record->Title
			// 	)
			// );
		}
	}

	// Request edit
	public function cms_requestedit($data, $form) {
		return $this->workflowAction('WorkflowRequest', 'requestedit', $data['ID'], $data['WorkflowComment']);
	}

	// Deny
	public function cms_deny($data, $form) {
		return $this->workflowAction('WorkflowRequest', 'deny', $data['ID'], $data['WorkflowComment']);
	}
	
	// Cancel
	public function cms_cancel($data, $form) {
		return $this->workflowAction('WorkflowRequest', 'cancel', $data['ID'], $data['WorkflowComment']);
	}
	
	// Comment (no workflow status change)
	public function cms_comment($data, $form) {
		return $this->workflowAction('WorkflowRequest', 'comment', $data['ID'], $data['WorkflowComment']);
	}

	/**
	 * Process a workflow action.
	 * @param string $workflowClass The sub-class of WorkflowRequest that is expected.
	 * @param string $actionName The action method to call on the given WorkflowRequest objec.t
	 * @param int $id The ID# of the page.
	 * @param string $comment The comment to attach.
	 * @param string $successMessage The message to show on success.
	 */
	function workflowAction($workflowClass,  $actionName, $id, $comment) {
		if(is_numeric($id)) {
			// For 2.3 and 2.4 compatibility
			$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";

			$page = DataObject::get_by_id("SiteTree", $id);
			if(!$page) $page = Versioned::get_one_by_stage("SiteTree", "Live", "{$bt}SiteTree{$bt}.{$bt}ID{$bt} = $id");
			if(!$page) return new HTTPResponse("Can't find Page #$id", 400);
		} else {
			return new HTTPResponse("Bad ID", 400);
		}
		
		// If we are creating and approving a workflow in one step, then don't bother emailing
		$notify = !($actionName == 'action' && !$page->openWorkflowRequest($workflowClass));
		
		if($request = $page->openOrNewWorkflowRequest($workflowClass, $notify)) {
			$request->clearMembersEmailed();

			if($successMessage = $request->$actionName($comment, null, $notify)) {
				FormResponse::get_page($id);

				$title = Convert::raw2js($page->TreeTitle());
				FormResponse::add("$('sitetree').setNodeTitle($id, \"$title\");");
		
				// gather members for status output
				if($notify) {
					$peeps = $request->getMembersEmailed();
					if ($peeps && $peeps->Count()) {
						$emails = '';
						foreach($peeps as $peep) {
							if ($peep->Email) $emails .= $peep->Email.', ';
						}
						$emails = trim($emails, ', ');
					} else { $emails = 'no-one'; }
				} else {
					$emails = "no-one";
				}
				
				if ($successMessage) {
					FormResponse::status_message(sprintf($successMessage, $emails), 'good');
					return FormResponse::respond();
				} else {
					return;
				}
			}
		}

		// Failure
		FormResponse::status_message(_t('SiteTreeCMSWorkflow.WORKFLOW_ACTION_FAILED', 
			"There was an error when processing your workflow request."), 'bad');
		return FormResponse::respond();
	}
	

}
?>
