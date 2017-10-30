<?php
namespace Skill\Db;


/**
 * @author Michael Mifsud <info@tropotek.com>
 * @link http://www.tropotek.com/
 * @license Copyright 2015 Michael Mifsud
 */
class Entry extends \Tk\Db\Map\Model implements \Tk\ValidInterface, \App\Db\StatusInterface
{
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_NOT_APPROVED = 'not approved';


    /**
     * @var int
     */
    public $id = 0;

    /**
     * @var int
     */
    public $collectionId = 0;

    /**
     * @var int
     */
    public $courseId = 0;

    /**
     * @var int
     */
    public $userId = 0;

    /**
     * @var int
     */
    public $placementId = 0;

    /**
     * @var string
     */
    public $title = '';

    /**
     * @var string
     */
    public $assessor = '';

    /**
     * The number of days the student was absent for this placement
     * @var int
     */
    public $absent = 0;

    /**
     * @var float
     */
    public $average = 0.0;

    /**
     * @var float
     */
    public $weightedAverage = 0.0;

    /**
     * @var int|null
     */
    public $confirm = null;

    /**
     * @var string
     */
    public $status = self::STATUS_PENDING;

    /**
     * @var string
     */
    public $notes = '';

    /**
     * @var \DateTime
     */
    public $modified = null;

    /**
     * @var \DateTime
     */
    public $created = null;


    /**
     * @var Collection
     */
    private $collection = null;

    /**
     * @var \App\Db\Course
     */
    private $course = null;

    /**
     * @var \App\Db\User
     */
    private $user = null;

    /**
     * @var \App\Db\Placement
     */
    private $placement = null;



    /**
     * Course constructor.
     */
    public function __construct()
    {
        $this->modified = \Tk\Date::create();
        $this->created = \Tk\Date::create();
    }

    public function save()
    {
        $this->average = $this->calcAverage();
        $this->weightedAverage = $this->calcWeightedAverage();
        parent::save();
    }


    /**
     * @return \Skill\Db\Collection|null|\Tk\Db\Map\Model|\Tk\Db\ModelInterface
     */
    public function getCollection()
    {
        if (!$this->collection) {
            $this->collection = CollectionMap::create()->find($this->collectionId);
        }
        return $this->collection;
    }

    /**
     * @return \App\Db\Course|null|\Tk\Db\Map\Model|\Tk\Db\ModelInterface
     */
    public function getCourse()
    {
        if (!$this->course) {
            $this->course = \App\Db\CourseMap::create()->find($this->courseId);
        }
        return $this->course;
    }

    /**
     * @return \App\Db\User|null|\Tk\Db\Map\Model|\Tk\Db\ModelInterface
     */
    public function getUser()
    {
        if (!$this->user) {
            $this->user = \App\Db\UserMap::create()->find($this->userId);
        }
        return $this->user;
    }

    /**
     * @return \App\Db\Placement|null|\Tk\Db\Map\Model|\Tk\Db\ModelInterface
     */
    public function getPlacement()
    {
        if (!$this->placement) {
            $this->placement = \App\Db\PlacementMap::create()->find($this->placementId);
        }
        return $this->placement;
    }

    /**
     * return the status list for a select field
     * @return array
     */
    public static function getStatusList()
    {
        return \Tk\Form\Field\Select::arrayToSelectList(\Tk\Object::getClassConstants(__CLASS__, 'STATUS'));
    }

    /**
     * Get the entry values average, this average is not weighted to the Domain.weight values
     *
     * @return float
     */
    public function calcAverage()
    {
        $grades = array();
        $valueList = EntryMap::create()->findValue($this->getId());
        foreach ($valueList as $value) {
            $grades[$value->item_id] = (int)$value->value;
        }
        return round(\Tk\Math::average($grades), 2);
    }

    /**
     * @return float
     */
    public function calcWeightedAverage()
    {
        $grades = array();
        $valueList = EntryMap::create()->findValue($this->getId());
        foreach ($valueList as $value) {
            if (!$value->value && !$this->getCollection()->includeZero) continue;
            /** @var \Skill\Db\Item $item */
            $item = \Skill\Db\ItemMap::create()->find($value->item_id);
            $val = (int)$value->value;
            $grades[$item->getDomain()->getId()][$value->item_id] = $val;
        }
        $avgs = array();
        foreach ($grades as $domainId => $valArray) {
            /** @var \Skill\Db\Domain $domain */
            $domain = \Skill\Db\DomainMap::create()->find($domainId);
            $avgs[$domainId] = round(\Tk\Math::average($valArray) * $domain->weight, 2);
        }
        if (!count($grades)) return 0;
        return (array_sum($avgs)/count($grades)) * ($this->getCollection()->getScaleLength()-1);
    }


    /**
     *
     */
    public function validate()
    {
        $errors = array();
        if ((int)$this->collectionId <= 0) {
            $errors['collectionId'] = 'Invalid Collection ID';
        }
        if ((int)$this->courseId <= 0) {
            $errors['courseId'] = 'Invalid Course ID';
        }
        if ((int)$this->userId <= 0) {
            $errors['userId'] = 'Invalid User ID';
        }
        if (!$this->assessor) {
            $errors['assessor'] = 'Please enter a valid assessors name';
        }
        return $errors;
    }

    /**
     * return tru to trigger the status change events
     *
     * @param \App\Db\Status $status
     * @return boolean
     */
    public function triggerStatusChange($status)
    {
        $prevStatusName = $status->getPreviousName();
        switch($status->name) {
            case self::STATUS_PENDING:
                if (!$prevStatusName)
                    return true;
            case self::STATUS_APPROVED:
                if (!$prevStatusName || self::STATUS_PENDING == $prevStatusName)
                    return true;
            case self::STATUS_NOT_APPROVED:
                if (self::STATUS_PENDING == $prevStatusName)
                    return true;
        }
        return false;
    }

    /**
     * @param \App\Db\Status $status
     * @param \App\Db\MailTemplate $mailTemplate
     * @return null|\Tk\Mail\CurlyMessage
     */
    public function sendStatusMessage($status, $mailTemplate)
    {
        $placement = $this->getPlacement();
        if (!$placement->getPlacementType()->notifications) {
            \Tk\Log::warning('PlacementType[' . $placement->getPlacementType()->name . '] Notifications Disabled');
            return null;
        }
        $profile = $status->getProfile();
        $course = $status->getCourse();
        $student = $placement->getUser();
        $supervisor = $placement->getSupervisor();
        $company = $placement->getCompany();
        $courseName = $profile->name;
        if ($course) {
            $courseName = $course->name;
        }

        $message = \Tk\Mail\CurlyMessage::create($mailTemplate->template);
        $message->setSubject($this->getCollection()->name . ' Entry ' . ucfirst($status->name) . ' for ' . $placement->getTitle(true) . ' ');
        $message->setFrom(\Tk\Mail\Message::joinEmail($profile->email, $courseName));

        // Setup the message vars
        \App\Util\StatusMessage::setRecipientType($message, $mailTemplate->recipient);
        \App\Util\StatusMessage::setProfile($message, $profile);
        \App\Util\StatusMessage::setCourse($message, $course);
        \App\Util\StatusMessage::setStatus($message, $status);
        \App\Util\StatusMessage::setStudent($message, $student);
        \App\Util\StatusMessage::setSupervisor($message, $supervisor, $profile);
        \App\Util\StatusMessage::setCompany($message, $company, $profile);
        \App\Util\StatusMessage::setPlacement($message, $placement);

        // TODO: add entry details
        $message->set('entry::id', $this->getVolatileId());
        $message->set('entry::title', $this->title);
        $message->set('entry::assessor', $this->assessor);
        $message->set('entry::status', $this->status);
        $message->set('entry::notes', nl2br($this->notes, true));


        switch ($mailTemplate->recipient) {              // <<< ??????? this is not elegant, refactor at some point
            case \App\Db\MailTemplate::RECIPIENT_STUDENT:
                if ($student) {
                    $message->addTo(\Tk\Mail\Message::joinEmail($student->email, $student->name));
                }
                break;
            case \App\Db\MailTemplate::RECIPIENT_STAFF:
                $staffList = \App\Db\UserMap::create()->findFiltered(array('profileId' => $profile->getId(), 'role' => \App\Db\UserGroup::ROLE_STAFF));
                if (count($staffList)) {
                    /** @var \App\Db\User $s */
                    foreach ($staffList as $s) {
                        $message->addBcc(\Tk\Mail\Message::joinEmail($s->email, $s->name));
                    }
                    $message->addTo(\Tk\Mail\Message::joinEmail($profile->email, $courseName));
                }
                break;
            case \App\Db\MailTemplate::RECIPIENT_COMPANY:
                if ($company) {
                    $message->addTo(\Tk\Mail\Message::joinEmail($company->email, $company->name));
                }
                break;
//            case \App\Db\MailTemplate::RECIPIENT_SUPERVISOR:
//                if ($supervisor) {
//                    $message->addTo(\Tk\Mail\Message::joinEmail($supervisor->email, $supervisor->name));
//                }
//                break;
        }

        return $message;
    }
}