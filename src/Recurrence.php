<?php

namespace Dunice;

use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;

class Recurrence
{
    const TYPE_DAILY           = 'daily';
    const TYPE_WEEKLY          = 'weekly';
    const TYPE_MONTHLY         = 'monthly';
    const LIMIT                = '20 years';
    const MONTHLY_REPEAT_WEEK  = 'day-of-week';
    const MONTHLY_REPEAT_MONTH = 'day-of-month';

    /**
     * Days
     */
    const DAYS_LONG  = [
        'Sunday',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];
    const DAYS_SHORT = [
        'sun',
        'mon',
        'tue',
        'wed',
        'thu',
        'fri',
        'sat',
    ];

    /**
     * @var Carbon
     */
    private $initial = null;

    /**
     * @var string
     */
    private $type = self::TYPE_DAILY;

    /**
     * @var int
     */
    private $interval = 1;

    /**
     * @var Carbon
     */
    private $limit = self::LIMIT;

    /**
     * @var null
     */
    private $repeat = null;

    public function __construct()
    {
        $this->setInterval()
            ->setInitial()
            ->setLimit()
            ->setRepeat()
            ->setType();
    }

    /**
     * @param array $arr
     *
     * @return $this
     */
    public function fromArray(array $arr)
    {
        foreach($arr as $key => $value) {
            $method = 'set' . ucfirst($key);
            if(method_exists($this, $method)) {
                $this->{$method}($value);
            }
        }

        return $this;
    }

    /**
     * @param null $initial
     *
     * @return $this
     */
    public function setInitial($initial = null, $timezone = 'UTC')
    {
        $this->initial = $initial
            ? new Carbon($initial, $timezone)
            : new Carbon();

        return $this;
    }

    /**
     * @param $type
     *
     * @return $this
     */
    public function setType($type = null)
    {
        $this->type = $type ?? self::TYPE_DAILY;

        return $this;
    }

    /**
     * @param $interval
     *
     * @return $this
     */
    public function setInterval($interval = null)
    {
        $this->interval = $interval ?? 1;

        return $this;
    }

    /**
     * @param $limit
     *
     * @return $this
     */
    public function setLimit($limit = null)
    {
        $this->limit = $limit
            ? new Carbon($limit)
            : new Carbon(date('Y-m-d', strtotime('+' . self::LIMIT)));

        return $this;
    }

    /**
     * @param $repeat
     *
     * @return $this
     */
    public function setRepeat($repeat = null)
    {
        if(is_array($repeat)) {
            foreach($repeat as $key => $value) {
                $short = array_search(mb_strtolower($value), self::DAYS_SHORT);
                $long  = array_search(ucfirst($value), self::DAYS_LONG);

                if(is_int($short)) {
                    $repeat[$key] = $short;
                }

                if(is_int($long)) {
                    $repeat[$key] = $long;
                }

                if(is_bool($short) && is_bool($long)) {
                    throw new \Exception('Invalid repeat key: ' . $value);
                }
            }

            sort($repeat);
            $repeat = array_unique($repeat);
        }

        $this->repeat = $repeat;

        return $this;
    }

    public function run()
    {
        $this->validate();

        $this->limit->endOfDay();

        return $this->{$this->type}();
    }

    private function daily()
    {
        $dates    = [];
        $next     = $this->initial;
        $interval = $this->interval >= 1 ? $this->interval : 1;

        while ($next->getTimestamp() <= $this->limit->getTimestamp()) {
            $next->addDays($interval);

            if ($next->getTimestamp() <= $this->limit->getTimestamp()) {
                $dates[] = (string)$next;
            }
        }

        return $dates;
    }

    private function weekly()
    {
        $dates    = [];
        $original = $this->initial;
        $next     = $this->initial->copy();
        $interval = $this->interval >= 1 ? $this->interval : 1;
        $counter  = 0;

        while ($next->getTimestamp() <= $this->limit->getTimestamp()) {
            foreach ($this->repeat as $repeat) {
                if($repeat <= $next->dayOfWeek && !$counter) {
                    continue;
                }

                $next->next($repeat);

                if ($next->getTimestamp() <= $this->limit->getTimestamp()) {
                    $dates[] = (string)$next->setTime($original->hour, $original->minute, $original->second);
                }
            }

            $next->addWeeks($interval - 1);
            ++$counter;
        }

        return $dates;
    }

    private function monthly()
    {
        $original = $this->initial;
        $next     = $this->initial->copy();
        $interval = $this->interval >= 1 ? $this->interval : 1;
        $dates    = [];
        $reset    = false;

        while ($next->getTimestamp() <= $this->limit->getTimestamp()) {
            switch ($this->repeat) {
                default:
                case self::MONTHLY_REPEAT_MONTH:
                    $checker = clone $next;
                    $checker->addMonth($interval);

                    if($checker->day !== $original->day) {
                        $next->addMonth($interval * 2);
                        continue;
                    }

                    $next->addMonth($interval);

                    break;

                case self::MONTHLY_REPEAT_WEEK:
                    $next->startOfMonth()->addMonth($interval);

                    if(!$next->nthOfMonth($original->weekOfMonth, $original->dayOfWeek)) {
                        $next->modify('last ' . $original->format('l') . ' of this month');
                    }

                    break;
            }

            if ($next->getTimestamp() <= $this->limit->getTimestamp()) {
                $dates[] = (string)$next->setTime($original->hour, $original->minute, $original->second);
            }
        }

        return $dates;
    }

    public function validate()
    {
        if(
            $this->type !== self::TYPE_DAILY
            && $this->type !== self::TYPE_MONTHLY
            && $this->type !== self::TYPE_WEEKLY
        ) {
            throw new \Exception(Lang::get('flight.invalidRecurrenceType'));
        }

        $recurrence     = [
            'type' => $this->type,
        ];
        $fieldsValidate = [
            'ends_at' => 'date|date_format:Y-m-d',
        ];

        if($this->type === self::TYPE_MONTHLY) {
            $fieldsValidate['repeat'] = 'in:' . self::MONTHLY_REPEAT_WEEK . ',' . self::MONTHLY_REPEAT_MONTH;
            $recurrence['repeat']     = $this->repeat;
        }

        $validator = Validator::make($recurrence, $fieldsValidate);
        if ($validator->fails()) {
            throw new \Exception($validator->messages());
        }
    }
}
