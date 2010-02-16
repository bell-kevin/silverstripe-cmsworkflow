<?php
/**
 * Report to show pages scheduled to be deleted
 *
 * @package cmsworkflow
 * @subpackage reports
 */
class PagesScheduledForDeletionReport extends SSReport {
	function title() {
		return _t('PagesScheduledForDeletionReport.TITLE',"Pages scheduled for deletion");
	}
	
	function parameterFields() {
		$params = new FieldSet();
		
		if (class_exists('Subsite') && $subsites = DataObject::get('Subsite')) {
			$options = $subsites->toDropdownMap('ID', 'Title');
			$params->push(new TreeMultiselectField('Subsites', 'Sites', $options));
		}
		
		$params->push(new PopupDateTimeField('StartDate', 'Start date'));
		$params->push(new PopupDateTimeField('EndDate', 'End date'));
		
		return $params;
	}
	
	function columns() {
		$fields = array(
			'Title' => 'Title',
			'ExpiryDate' => array(
				'title' => 'Will be deleted at',
				'casting' => 'SSDatetime->Full'
			),
			'ApproverName' => 'Approved by',
			'ID' => array(
				'title' => 'Actions',
				'formatting' => '<a href=\"admin/show/$value\">Edit in CMS</a>'
			),
			'AbsoluteLink' => array(
				'title' => 'Links',
				'formatting' => '$value <a href=\"$value?stage=Live\">(live)</a> <a href=\"$value?stage=Stage\">(draft)</a>'
			)
		);
		
		if(class_exists('Subsite')) {
			$fields['SubsiteTitle'] = 'Subsite';
		}
		
		return $fields;
	}
	
	function sourceQuery($params) {
		// Manually manage the subsite filtering
		if(ClassInfo::exists('Subsite')) Subsite::$disable_subsite_filter = true;
		
		$wheres = array();
		
		if(!empty($params['Subsites'])) {
			// 'any' wasn't selected
			$subsiteIds = array();
			foreach(explode(',', $params['Subsites']) as $subsite) {
				if(is_numeric($subsite)) $subsiteIds[] = $subsite;
			}
			$wheres[] = 'SubsiteID IN(' . implode(',' , $subsiteIds) . ')';
		}
		
		$startDate = !empty($params['StartDate']) ? $params['StartDate'] : null;
		$endDate = !empty($params['EndDate']) ? $params['EndDate'] : null;
		
		if($startDate) {
			if(count(explode('/', $startDate['Date'])) == 3) {
				list($d, $m, $y) = explode('/', $startDate['Date']);
				$startDate['Time'] = $startDate['Time'] ? $startDate['Time'] : '00:00:00';
				$startDate = @date('Y-m-d H:i:s', strtotime("$y-$m-$d {$startDate['Time']}"));
			} else {
				$startDate = null;
			}
		}
		
		if($endDate) {
			if(count(explode('/', $endDate['Date'])) == 3) {
				list($d,$m,$y) = explode('/', $endDate['Date']);
				$endDate['Time'] = $endDate['Time'] ? $endDate['Time'] : '23:59:59';
				$endDate = @date('Y-m-d H:i:s', strtotime("$y-$m-$d {$endDate['Time']}"));
			} else {
				$endDate = null;
			}
		}
		
		if($startDate && $endDate) {
			$wheres[] = "ExpiryDate >= '".Convert::raw2sql($startDate)."' AND ExpiryDate <= '".Convert::raw2sql($endDate)."'";
		} else if($startDate && !$endDate) {
			$wheres[] = "ExpiryDate >= '".Convert::raw2sql($startDate)."'";
		} else if(!$startDate && $endDate) {
			$wheres[] = "ExpiryDate <= '".Convert::raw2sql($endDate)."'";
		} else {
			$wheres[] = "ExpiryDate >= '".SSDatetime::now()->URLDate()."'";
		}
		
		$query = singleton("SiteTree")->extendedSQL(join(' AND ', $wheres), null, null, 
			"LEFT JOIN WorkflowRequest on WorkflowRequest.PageID = SiteTree.ID"
		);
		
		$query->from[] = "LEFT JOIN Member AS Approver ON WorkflowRequest.ApproverID = Approver.ID";
		$query->select[] = 'CONCAT(Approver.FirstName, \' \', Approver.Surname) AS ApproverName';

		// Manually manage the subsite filtering
		if(ClassInfo::exists('Subsite')) {
			$query->from[] = "LEFT JOIN Subsite ON SiteTree.SubsiteID = Subsite.ID";
			// $query->select[] = "CASE WHEN Subsite.Title IS NOT '0' THEN Subsite.Title ELSE 'Main site' END AS SubsiteTitle";
			$query->select[] = "Subsite.Title AS SubsiteTitle";
			Subsite::$disable_subsite_filter = false;
		}
		
		// Manually manage the subsite filtering
		if(ClassInfo::exists('Subsite')) Subsite::$disable_subsite_filter = false;
		
		return $query;
	}
}
	
