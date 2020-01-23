<?php

namespace UncleCheese\EventCalendar\Pages;

use Carbon\Carbon;
use UncleCheese\EventCalendar\Helpers\CalendarUtil;
use UncleCheese\EventCalendar\Pages\Calendar;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\RSS\RSSFeed;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\View\Requirements;
use \PageController;

class CalendarController extends PageController 
{
	private static $allowed_actions = [
		'show',
		'month',
		'year',
		'rss',
		'today',
		'week',
		'weekend',
		'ical',
		'ics',
		'monthjson',
		'MonthJumpForm'
    ];

	protected $view;

	/**
	 * @var Carbon
	 */
	protected $startDate;

	/**
	 * @var Carbon
	 */
	protected $endDate;

	public function init()
	{
		parent::init();
		RSSFeed::linkToFeed($this->Link() . "rss", $this->RSSTitle ? $this->RSSTitle : $this->Title);
		Requirements::themedCSS('calendar','event_calendar');
		if (!Calendar::config()->jquery_included) {
			Requirements::javascript(THIRDPARTY_DIR.'/jquery/jquery.js');
		}
		Requirements::javascript('unclecheese/silverstripe-event-calendar:client/js/calendar.js');
	}

	public function index(HTTPRequest $r) {

		$this->extend('index', $r);

		switch ($this->DefaultView) {
			case "month":
				return $this->redirect($this->Link('show/month'));
				break;
			case "week":
				$this->setWeekView();
				// prevent pagination on these default views
				$this->EventsPerPage = 999;
				$e = $this->Events();
				if ($e->count() > 0) {
					return ['Events' => $e];
				} else {
					$this->setMonthView();
					return [];
				}
				break;
			case "today":
				// prevent pagination on these default views
				$this->EventsPerPage = 999;
				$this->setTodayView();
				$e = $this->Events();
				if ($e->count() > 0) {
					return ['Events' => $e];
				} else {
					$this->setWeekView();
					return [];
				}
				break;
			default:
				$this->setDefaultView();
				return $this->respond();
				break;
		}
	}

	public function today(HTTPRequest $r)
	{
		$this->setTodayView();
		return $this->respond();
	}

	public function week(HTTPRequest $r)
	{
		$this->setWeekView();
		return $this->respond();
	}

	public function weekend(HTTPRequest $r) {
		$this->setWeekendView();
		return $this->respond();
	}

	public function month(HTTPRequest $r) {
		$this->setMonthView();
		return $this->respond();
	}

	public function show(HTTPRequest $r) {
		$this->parseURL($r);
		return $this->respond();
	}

	public function rss() {
		$this->setDefaultView();
		$events = $this->Events();
		foreach($events as $event) {
			$event->Title = strip_tags($event->DateRange()) . " : " . $event->getTitle();
			$event->Description = $event->getContent();
		}
		$rssTitle = $this->RSSTitle 
			? $this->RSSTitle 
			: sprintf(
				_t(Calendar::class.'.UPCOMINGEVENTSFOR', "Upcoming Events for %s"),
				$this->Title
			);
		$rss = RSSFeed::create($events, $this->Link(), $rssTitle, "", "Title", "Description");

		if (is_int($rss->lastModified)) {
			HTTP::register_modification_timestamp($rss->lastModified);
			header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $rss->lastModified) . ' GMT');
		}
		if (!empty($rss->etag)) {
			HTTP::register_etag($rss->etag);
		}
		$xml = str_replace('&nbsp;', '&#160;', $rss->renderWith('SilverStripe\Control\RSS\RSSFeed'));
		$xml = preg_replace('/<!--(.|\s)*?-->/', '', $xml);
		$xml = trim($xml);
		HTTP::add_cache_headers();
		$this->getResponse()->addHeader('Content-Type', 'application/rss+xml');
		$this->getResponse()->setBody($xml);

		return $this->getResponse();
	}

	public function monthjson(SS_HTTPRequest $r) {
		if (!$r->param('ID')) {
			return false;
		}
        //Increase the per page limit to 500 as the AJAX request won't look for further pages
        $this->EventsPerPage = 500;

		$this->startDate = Carbon::parse(
			CalendarUtil::get_date_from_string($r->param('ID'))
		);
		$this->endDate = Carbon::parse($this->startDate)->endOfMonth();

		$json = [];
		$counter = clone $this->startDate;
		while ($counter->get() <= $this->endDate->get()) {
			$d = $counter->format('Y-m-d');
			$json[$d] = array (
				'events' => array ()
			);
			$counter->tomorrow();
		}
		$list = $this->Events();
		foreach($list as $e) {
			foreach($e->getAllDatesInRange() as $date) {
				if(isset($json[$date])) {
					$json[$date]['events'][] = $e->getTitle();
				}
			}
		}
		return Convert::array2json($json);
	}

	/**
	 * @return Carbon
	 */
	public function getStartDate()
	{
		return $this->startDate;
	}

	/**
	 * @return Carbon
	 */
	public function getEndDate()
	{
		return $this->endDate;
	}

	public function getView()
	{
		return $this->view;
	}

	public function setDefaultView()
	{
		$this->view = 'default';
		$this->startDate = Carbon::now();
		$this->endDate = Carbon::now()->addMonths($this->DefaultFutureMonths);
	}

	public function setTodayView()
	{
		$this->view = 'day';
		$this->startDate = Carbon::now();
		$this->endDate = Carbon::now();
	}

	public function setWeekView()
	{
		$this->view = 'week';
		$this->startDate = Carbon::now()->startOfWeek();
		$this->endDate = Carbon::now()->endOfWeek();
		if (CalendarUtil::get_first_day_of_week() == Carbon::MONDAY) {
			$this->startDate = $this->startDate->tomorrow();
			$this->endDate = $this->endDate->tomorrow();
		}
	}

	public function setWeekendView()
	{
 		$this->view = 'weekend';
		$start = Carbon::now();
		if ($start->format('w') == Carbon::SATURDAY) {
			$start = $start->yesterday();
		} elseif ($start->format('w') != Carbon::FRIDAY) {
			$start = $start->next(Carbon::FRIDAY);
		}
		$this->startDate = $start;
		$this->endDate = Carbon::parse($this->startDate)->next(Carbon::SUNDAY);
	}

	public function setMonthView()
	{
		$this->view = 'month';
		$this->startDate = Carbon::now()->startOfMonth();
		$this->endDate = Carbon::parse($this->startDate)->endOfMonth();
	}

	public function getOffset()
	{
		if (!isset($_REQUEST['start'])) {
			$_REQUEST['start'] = 0;
		}
		return $_REQUEST['start'];
	}

	protected function getRangeLink($start, $end)
	{
		return parent::join_links(
			$this->Link(), 
			"show", 
			$start->format('Ymd'), 
			$end->format('Ymd')
		);
	}

	public function respond()
	{
		if (Director::is_ajax()) {
			return $this->renderWith('EventList');
		}
		return [];
	}

	/**
	 * Send ical file of multiple upcoming events using ICSWriter
	 *
	 * @todo Support recurring events.
	 * @see ICSWriter
	 * @author Alex Hayes <alex.hayes@dimension27.com>
	 */
	public function ical()
	{
		$writer = ICSWriter::create($this->data(), Director::absoluteURL('/'));
		$writer->sendDownload();
	}

	public function ics(HTTPRequest $r)
	{
		$feed = false;
		$announcement = false;
		$id = $r->param('ID');
		$oid = $r->param('OtherID');

		if (stristr($id, "ICS_") !== false) {
			$id = str_replace("ICS_", "", $id);
			$feed = true;
		} elseif(stristr($id, "announcement-") !== false) {
			$id = str_replace("announcement-","",$id);
			$announcement = true;
		}
		if (is_numeric($id) && $oid) {
			if (!$feed) {
				$event = DataObject::get(
					$announcement ? $this->data()->getDateTimeClass() : $this->data()->getEventClass()
				)->byID($id);
                // return if not found
                if (!$event) {
                    return $this->httpError(404);
                }
				$FILENAME = $announcement ? preg_replace("/[^a-zA-Z0-9s]/", "", $event->Title) : $event->URLSegment;
			}
			else {
				$FILENAME = preg_replace("/[^a-zA-Z0-9s]/", "", urldecode($_REQUEST['title']));
			}

			$FILENAME .= ".ics";
			$HOST = $_SERVER['HTTP_HOST'];
			$TIMEZONE = Calendar::config()->timezone;
			$LANGUAGE = Calendar::config()->language;
			$CALSCALE = "GREGORIAN";
			$parts = explode('-', $oid);
			$START_TIMESTAMP = $parts[0];
			$END_TIMESTAMP = $parts[1];
			if (!$feed) {
				$URL = $announcement ? $event->Calendar()->AbsoluteLink() : $event->AbsoluteLink();
			}
			else {
				$URL = "";
			}
			$TITLE = $feed ? $_REQUEST['title'] : $event->Title;
			$CONTENT = $feed ? $_REQUEST['content'] : $event->obj('Content')->Summary();
			$LOCATION = $feed ? $_REQUEST['location'] : $event->Location;

			$this->getResponse()->addHeader('Cache-Control','private');
			$this->getResponse()->addHeader('Content-Description','File Transfer');
			$this->getResponse()->addHeader('Content-Type','text/calendar');
			$this->getResponse()->addHeader('Content-Transfer-Encoding','binary');
			if (stristr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
 				$this->getResponse()->addHeader("Content-disposition", "filename=".$FILENAME."; attachment;");
 			} else {
 				$this->getResponse()->addHeader("Content-disposition", "attachment; filename=".$FILENAME);
			}
			
			$result = trim(strip_tags($this->customise(
				[
					'HOST' => $HOST,
					'LANGUAGE' => $LANGUAGE,
					'TIMEZONE' => $TIMEZONE,
					'CALSCALE' => $CALSCALE,
					'START_TIMESTAMP' => $START_TIMESTAMP,
					'END_TIMESTAMP' => $END_TIMESTAMP,
					'URL' => $URL,
					'TITLE' => $TITLE,
					'CONTENT' => $CONTENT,
					'LOCATION' => $LOCATION
				]
			)->renderWith(['ics'])));

			return $result;
		}
		
		$this->redirectBack();
	}

	public function parseURL(HTTPRequest $r)
	{
		if(!$r->param('ID')) return;
		$this->startDate = sfDate::getInstance(CalendarUtil::get_date_from_string($r->param('ID')));
		if($r->param('OtherID')) {
			$this->view = "range";
			$this->endDate = sfDate::getInstance(CalendarUtil::get_date_from_string($r->param('OtherID')));
		}
		else {
			$d = clone $this->startDate;
			switch(strlen(str_replace("-","",$r->param('ID')))) {
				case 8:
				$this->view = "day";
				$this->endDate = sfDate::getInstance($d->get()+1);
				break;

				case 6:
				$this->view = "month";
				$this->endDate = sfDate::getInstance($d->finalDayOfMonth()->date());
				break;

				case 4:
				$this->view = "year";
				$this->endDate = sfDate::getInstance($d->finalDayOfYear()->date());
				break;

				default:
				$this->view = "default";
				$this->endDate = sfDate::getInstance($d->addMonth($this->DefaultFutureMonths)->date());
				break;
			}
		}
	}

	public function Events()
	{
		$event_filter = null;
		$announcement_filter = null;
		$endDate = $this->endDate;

		if ($search = $this->getRequest()->getVar('s')) {
			$s = Convert::raw2sql($search);
			$event_filter = "\"SiteTree\".\"Title\" LIKE '%$s%' OR \"SiteTree\".\"Content\" LIKE '%$s%'";
			$announcement_filter = "\"CalendarAnnouncement\".\"Title\" LIKE '%$s%' OR \"CalendarAnnouncement\".\"Content\" LIKE '%$s%'";
			$this->SearchQuery = $search;
			$endDate = sfDate::getInstance()->addMonth($this->DefaultFutureMonths);
		}

		$all = $this->data()->getEventList(
			$this->startDate ? $this->startDate->date() : null,
			$endDate ? $endDate->date() : null,
			$event_filter,
			null,
			$announcement_filter
		);

		$all_events_count = $all->count();
		$list = $all->limit($this->EventsPerPage, $this->getOffset());
		$next = $this->getOffset()+$this->EventsPerPage;
		$this->MoreEvents = ($next < $all_events_count);
		$this->MoreLink = HTTP::setGetVar("start", $next);
		return $list;
	}

	public function DateHeader()
	{
		switch ($this->view) {
			case "day":
				return CalendarUtil::localize($this->startDate->get(), null, CalendarUtil::ONE_DAY_HEADER);
				break;
			case "month":
				return CalendarUtil::localize($this->startDate->get(), null, CalendarUtil::MONTH_HEADER);
				break;
			case "year":
				return CalendarUtil::localize($this->startDate->get(), null, CalendarUtil::YEAR_HEADER);
				break;
			case "range":
			case "week":
			case "weekend":
				list($strStartDate, $strEndDate) = CalendarUtil::get_date_string($this->startDate->date(), $this->endDate->date());
				return $strStartDate.$strEndDate;
				break;

			default:
				return $this->DefaultDateHeader;
				break;
		}
	}

	public function CurrentAction($a)
	{
		return $this->getAction() == $a;
	}

	public function PreviousDayLink()
	{
		$start = Carbon::parse($this->startDate)->yesterday();
		return $this->getRangeLink($start, $start);
	}

	public function NextDayLink()
	{
		$start = Carbon::parse($this->startDate)->tomorrow();
		return $this->getRangeLink($start, $start);
	}

	public function PreviousWeekLink()
	{
		$start = Carbon::parse($this->startDate)->subtractWeek();
		$end = Carbon::parse($this->endDate)->subtractWeek();
		return $this->getRangeLink($start, $end);
	}

	public function NextWeekLink()
	{
		$start = Carbon::parse($this->startDate)->addWeek();
		$end = Carbon::parse($this->endDate)->addWeek();
		return $this->getRangeLink($start, $end);
	}

	public function NextMonthLink()
	{
		$start = Carbon::parse($this->startDate)->addMonth();
		$end = Carbon::parse($start)->endOfMonth();
		return $this->getRangeLink($start, $end);
	}

	public function PreviousMonthLink()
	{
		$start = Carbon::parse($this->startDate)->subMonth();
		$end = Carbon::parse($start)->endOfMonth();
		return $this->getRangeLink($start, $end);
	}

	public function NextWeekendLink()
	{
		return $this->NextWeekLink();
	}

	public function PreviousWeekendLink()
	{
		return $this->PreviousWeekLink();
	}

	/**
	 * @return bool
	 */
	public function IsSegment($segment)
	{
		switch ($segment) {
			case "today":
				return $this->startDate->format('Y-m-d') == $this->endDate->format('Y-m-d');
			case "week":
				if (CalendarUtil::get_first_day_of_week() == CalendarUtil::MONDAY) {
					return 
						($this->startDate->format('w') == CalendarUtil::MONDAY) 
						&& ($this->startDate->format('w') == CalendarUtil::SUNDAY);
				}
				return 
					($this->startDate->format('w') == CalendarUtil::SUNDAY) 
					&& ($this->endDate->format('w') == CalendarUtil::SATURDAY);
			case "month":
				return 
					($this->startDate->format('j') == 1) 
					&& (sfDate::getInstance($this->startDate)->finalDayOfMonth()->format('j') == $this->endDate->format('j'));
			case "weekend":
				return 
					($this->startDate->format('w') == CalendarUtil::FRIDAY) 
					&& ($this->endDate->format('w') == CalendarUtil::SUNDAY);
		}
	}

	public function MonthJumper()
	{
		return $this->renderWith(__CLASS__.'\MonthJumper');
	}

	/**
	 * @return Form
	 */
	public function MonthJumpForm()
	{
		$this->parseURL($this->getRequest());
		$dummy = clone $this->startDate;
		$yearRange = range(($dummy->subYears(3)->format('Y')), ($dummy->addYears(6)->format('Y')));
		$form = Form::create(
			$this,
			__FUNCTION__,
			FieldList::create(
				$m = DropdownField::create('Month','', CalendarUtil::get_months_map('%B')),
				$y = DropdownField::create('Year','', array_combine($yearRange, $yearRange))
			),
			FieldList::create(
				FormAction::create('doMonthJump', _t('Calendar.JUMP', 'Go'))
			)
		);

		if ($this->startDate) {
			$m->setValue($this->startDate->format('m'));
			$y->setValue($this->startDate->format('Y'));
		} else {
			$m->setValue(date('m'));
			$y->setValue(date('Y'));
		}

		return $form;
	}

	public function doMonthJump($data, $form)
	{
		return $this->redirect(
			parent::join_links(
				$this->Link('show'),
				$data['Year'].$data['Month']
			)
		);
	}
}
