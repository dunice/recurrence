<?php

namespace Dunice;

use Carbon\Carbon;

class Recurrence
{
    const TYPE_DAILY   = 'daily';
    const TYPE_WEEKLY  = 'weekly';
    const TYPE_MONTHLY = 'monthly';

    /**
     * Limit for the forever ends_at
     */
    const LIMIT = '20 years';

    /**
     * Monthly repeat
     */
    const MONTHLY_REPEAT_DAY_OF_MONTH = 'day-of-week';
    const MONTHLY_REPEAT_DAY_OF_WEEK  = 'day-of-month';

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
    protected $from;

    /**
     * @var string
     */
    protected $type = self::TYPE_DAILY;

    /**
     * @var int
     */
    protected $interval = 1;

    /**
     * @var Carbon
     */
    protected $limit = self::LIMIT;

    /**
     * @var null
     */
    protected $repeat;

    public function __construct(array $arr)
    {
        $this->fromArray($arr);
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
     * @param null $from
     *
     * @return $this
     */
    public function setFrom($from = null, $timezone = 'UTC')
    {
        $this->from = $from
            ? new Carbon($from, $timezone)
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
        $this->limit->endOfDay();

        return call_user_func_array([$this, $this->type], []);
    }

    protected function daily()
    {
        $dates    = [];
        $next     = $this->from;
        $interval = $this->interval >= 1 ? $this->interval : 1;

        while ($next->getTimestamp() <= $this->limit->getTimestamp()) {
            $next->addDays($interval);

            if ($next->getTimestamp() <= $this->limit->getTimestamp()) {
                $dates[] = (string)$next;
            }
        }

        return $dates;
    }

    protected function weekly()
    {
        $dates    = [];
        $original = $this->from;
        $next     = $this->from->copy();
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

    protected function monthly()
    {
        $original = $this->from;
        $next = $this->from->copy();
        $interval = $this->interval >= 1 ? $this->interval : 1;
        $dates = [];

        while ($next->getTimestamp() <= $this->limit->getTimestamp()) {
            switch ($this->repeat) {
                default:
                case self::MONTHLY_REPEAT_DAY_OF_WEEK:
                    $checker = clone $next;
                    $checker->addMonth($interval);

                    if($checker->day !== $original->day) {
                        $next->addMonth($interval * 2);
                        continue 2;
                    }

                    $next->addMonth($interval);

                    break;

                case self::MONTHLY_REPEAT_DAY_OF_MONTH:
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
}
