<?php
namespace Zumba\Amplitude;

use Psr\Log;

class Amplitude
{
    use Log\LoggerAwareTrait;

    const AMPLITUDE_API_URL = 'https://api2.amplitude.com/httpapi';

    const EXCEPTION_MSG_NO_API_KEY = 'API Key is required to log an event';
    const EXCEPTION_MSG_NO_EVENT_TYPE = 'Event Type is required to log or queue an event';
    const EXCEPTION_MSG_NO_USER_OR_DEVICE = 'Either user_id or device_id required to log an event';

    /**
     * The API key to use for all events generated by this instance
     *
     * @var string
     */
    protected $apiKey;

    /**
     * The event that will be used for the next event being tracked
     *
     * @var \Zumba\Amplitude\Event
     */
    protected $event;

    /**
     * The user ID to use for events generated by this instance
     *
     * @var string
     */
    protected $userId;

    /**
     * The user data to set on the next event logged to Amplitude
     *
     * @var array
     */
    protected $userProperties = [];

    /**
     * The device ID to use for events generated by this instance
     *
     * @var string
     */
    protected $deviceId;

    /**
     * Queue of events, used to allow generating events that might happen prior to amplitude being fully initialized
     *
     * @var \Zumba\Amplitude\Event[]
     */
    protected $queue = [];

    /**
     * Flag for if user is opted out of tracking
     *
     * @var boolean
     */
    protected $optOut = false;

    /**
     * Flag for if should save the last HTTP response for debugging purposes
     *
     * @var boolean True to enable saving the last response
     */
    protected $debugResponse = false;

    /**
     * Last response from logging event
     *
     * @var array|null
     */
    protected $lastHttpResponse;

    /**
     * Array of Amplitude instances
     *
     * @var \Zumba\Amplitude\Amplitude[]
     */
    private static $instances = [];

    /**
     * Singleton to get named instance
     *
     * Using this is optional, it depends on the use-case if it is better to use a singleton instance or just create
     * a new object directly.
     *
     * Useful if want to possibly send multiple events for the same user in a single page load, or even keep track
     * of multiple named instances, each could track to it's own api key and/or user/device ID.
     *
     * Each instance maintains it's own:
     * - API Key
     * - User ID
     * - Device ID
     * - User Properties
     * - Event Queue (if events are queued before the amplitude instance is initialized)
     * - Event object - for the next event that will be sent or queued
     * - Logger
     * - Opt out status
     *
     * @param string $instanceName Optional, can use to maintain multiple singleton instances of amplitude, each with
     *   it's own API key set
     * @return \Zumba\Amplitude\Amplitude
     */
    public static function getInstance($instanceName = 'default')
    {
        if (empty(self::$instances[$instanceName])) {
            self::$instances[$instanceName] = new static();
        }
        return self::$instances[$instanceName];
    }

    /**
     * Constructor, optionally sets the api key
     *
     * @param string $apiKey
     * @param \Psr\Log $logger
     */
    public function __construct($apiKey = null)
    {
        if (!empty($apiKey)) {
            $this->apiKey = (string)$apiKey;
        }
        // Initialize logger to be null logger
        $this->setLogger(new Log\NullLogger());
    }

    /**
     * Initialize amplitude
     *
     * This lets you set the api key, and optionally the user ID.
     *
     * @param string $apiKey Amplitude API key
     * @param string $userId
     * @return \Zumba\Amplitude\Amplitude
     */
    public function init($apiKey, $userId = null)
    {
        $this->apiKey = (string)$apiKey;
        if ($userId !== null) {
            $this->setUserId($userId);
        }
        return $this;
    }

    /**
     * Log any events that were queued before amplitude was initialized
     *
     * Note that api key, and either the user ID or device ID need to be set prior to calling this.
     *
     * @return \Zumba\Amplitude\Amplitude
     * @throws \LogicException
     */
    public function logQueuedEvents()
    {
        if (empty($this->queue)) {
            return $this;
        }
        foreach ($this->queue as $event) {
            $this->event = $event;
            $this->logEvent();
        }
        return $this->resetEvent()
            ->resetQueue();
    }

    /**
     * Clear out all events in the queue, without sending them to amplitude
     *
     * @return \Zumba\Amplitude\Amplitude
     */
    public function resetQueue()
    {
        $this->queue = [];
        return $this;
    }

    /**
     * Gets the event that will be used for the next event logged by call to logEvent() or queueEvent()
     *
     * You can also pass in an event or array of event properties.  If you pass in an event, it will be set as the
     * event to be used for the next call to queueEvent() or logEvent()
     *
     * @param null|array|\Zumba\Amplitude\Event Can pass in an event to set as the next event to run, or array to set
     *   properties on that event
     * @return \Zumba\Amplitude\Event
     */
    public function event($event = null)
    {
        if (!empty($event) && $event instanceof \Zumba\Amplitude\Event) {
            $this->event = $event;
        } elseif (empty($this->event)) {
            // Set the values that persist between tracking events
            $this->event = new Event();
        }
        if (!empty($event) && is_array($event)) {
            // Set properties on the event
            $this->event->set($event);
        }
        return $this->event;
    }

    /**
     * Resets the event currently in the process of being set up (what is returned by event())
     *
     * @return \Zumba\Amplitude\Amplitude
     */
    public function resetEvent()
    {
        $this->event = null;
        return $this;
    }

    /**
     * Log an event immediately
     *
     * Requires amplitude is already initialized and user ID or device ID is set.  If need to wait until amplitude
     * is initialized, use queueEvent() method instead.
     *
     * Can either pass in information to be logged, or can set up the Event object before hand, see the event()
     * method for more information
     *
     * @param string $eventType Required if not set on event object prior to calling this
     * @param array $eventProperties Optional, properties to set on event
     * @return \Zumba\Amplitude\Amplitude
     * @throws \LogicException Thorws exception if any of the requirments are not met, such as api key set
     */
    public function logEvent($eventType = '', array $eventProperties = [])
    {
        if ($this->optOut) {
            return $this;
        }
        // Sanity checking
        if (empty($this->apiKey)) {
            throw new \LogicException(static::EXCEPTION_MSG_NO_API_KEY);
        }
        $event = $this->event();
        $event->set($eventProperties);
        $event->eventType = $eventType ?: $event->eventType;
        // Set the persistent options on the event
        $this->setPersistentEventData();

        if (empty($event->eventType)) {
            throw new \LogicException(static::EXCEPTION_MSG_NO_EVENT_TYPE);
        }
        if (empty($event->userId) && empty($event->deviceId)) {
            throw new \LogicException(static::EXCEPTION_MSG_NO_USER_OR_DEVICE);
        }

        $this->sendEvent();

        // Reset the event for next call
        $this->resetEvent();

        return $this;
    }

    /**
     * Set the persistent data on the event object
     *
     * @return void
     */
    protected function setPersistentEventData()
    {
        $event = $this->event();
        if (!empty($this->userId)) {
            $event->userId = $this->userId;
        }
        if (!empty($this->deviceId)) {
            $event->deviceId = $this->deviceId;
        }
        if (!empty($this->userProperties)) {
            $event->setUserProperties($this->userProperties);
            $this->resetUserProperties();
        }
    }

    /**
     * Log or queue the event, depending on if amplitude instance is already set up or not
     *
     * Note that this is an internal queue, the queue is lost between page loads.
     *
     * This functions identically to logEvent, with the exception that if Amplitude is not yet set up, it queues the
     * event to be logged later (during same page load).
     *
     * If the API key, and either user ID or device ID are already set in the amplitude instance, and there is not
     * already events in the queue that have not been run, this will log the event immediately.  Note that having
     * the userId or deviceId set on the event itself does not affect if it queues the event or not, only if set on
     * the Amplitude instance.
     *
     * Otherwise it will queue the event, and will be run after the amplitude instance is initialized and
     * logQueuedEvents() method is run
     *
     * @param string $eventType
     * @param array $eventProperties
     * @return \Zumba\Amplitude\Amplitude
     * @throws \LogicException
     */
    public function queueEvent($eventType = '', array $eventProperties = [])
    {
        if ($this->optOut) {
            return $this;
        }
        $event = $this->event();
        $event->set($eventProperties);
        $event->eventType = $eventType ?: $event->eventType;

        // Sanity checking
        if (empty($event->eventType)) {
            throw new \LogicException(static::EXCEPTION_MSG_NO_EVENT_TYPE);
        }
        if (empty($this->queue) && !empty($this->apiKey) && (!empty($this->userId) || !empty($this->deviceId))) {
            // No need to queue, everything seems to be initialized already and queue has already been processed
            return $this->logEvent();
        }
        $this->queue[] = $event;
        $this->resetEvent();

        return $this;
    }

    /**
     * Set the user ID for future events logged
     *
     * Any set with this will take precedence over any set on the Event object
     *
     * @param string $userId
     * @return \Zumba\Amplitude\Amplitude
     */
    public function setUserId($userId)
    {
        $this->userId = (string)$userId;
        return $this;
    }

    /**
     * Set the device ID for future events logged
     *
     * Any set with this will take precedence over any set on the Event object
     *
     * @param string $deviceId
     * @return \Zumba\Amplitude\Amplitude
     */
    public function setDeviceId($deviceId)
    {
        $this->deviceId = (string)$deviceId;
        return $this;
    }

    /**
     * Set the user properties, will be sent with the next event sent to Amplitude
     *
     * Any set with this will take precedence over any set on the Event object
     *
     * If no events are logged, it will not get sent to Amplitude
     *
     * @param array $userProperties
     * @return \Zumba\Amplitude\Amplitude
     */
    public function setUserProperties(array $userProperties)
    {
        $this->userProperties = array_merge($this->userProperties, $userProperties);
        return $this;
    }

    /**
     * Resets user properties added with setUserProperties() if they have not already been sent in an event to Amplitude
     *
     * @return \Zumba\Amplitude\Amplitude
     */
    public function resetUserProperties()
    {
        $this->userProperties = [];
        return $this;
    }

    /**
     * Check if there are events in the queue that have not been sent
     *
     * @return boolean
     */
    public function hasQueuedEvents()
    {
        return !empty($this->queue);
    }

    /**
     * Resets all user information
     *
     * This resets the user ID, device ID previously set using setUserId or setDeviceId.
     *
     * If additional information was previously set using setUserProperties() method, and the event has not already
     * been sent to Amplitude, it will reset that information as well.
     *
     * Does not reset user information if set manually on an individual event in the queue.
     *
     * @return \Zumba\Amplitude\Amplitude
     */
    public function resetUser()
    {
        $this->setUserId(null);
        $this->setDeviceId(null);
        $this->resetUserProperties();
        return $this;
    }

    /**
     * Set opt out for the current user.
     *
     * If set to true, will not send any future events to amplitude for this amplitude instance.
     *
     * @param boolean $optOut
     * @return \Zumba\Amplitude\Amplitude
     */
    public function setOptOut($optOut)
    {
        $this->optOut = (bool)$optOut;
        return $this;
    }

    /**
     * Getter for currently set api key
     *
     * @return string|null
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * Getter for currently set user ID
     *
     * @return string|null
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Getter for currently set device ID
     *
     * @return string|null
     */
    public function getDeviceId()
    {
        return $this->deviceId;
    }

    /**
     * Getter for all currently set user properties, that will be automatically sent on next Amplitude event
     *
     * Once the properties have been sent in an Amplitude event, they will be cleared.
     *
     * @return array
     */
    public function getUserProperties()
    {
        return $this->userProperties;
    }

    /**
     * Get the current value for opt out.
     *
     * @return boolean
     */
    public function getOptOut()
    {
        return $this->optOut;
    }

    /**
     * Send the event currently set in $this->event to amplitude
     *
     * Requres $this->event and $this->apiKey to be set, otherwise it throws an exception.
     *
     * @return void
     * @throws \InternalErrorException If event or api key not set
     */
    protected function sendEvent()
    {
        if (empty($this->event) || empty($this->apiKey)) {
            throw new \InternalErrorException('Event or api key not set, cannot send event');
        }
        $ch = curl_init(static::AMPLITUDE_API_URL);
        if (!$ch) {
            // Could be a number of PHP environment problems, log a critical error
            $this->logger->critical(
                'Call to curl_init(' . static::AMPLITUDE_API_URL . ') failed, unable to send Amplitude event'
            );
            return;
        }
        $postFields = [
            'api_key' => $this->apiKey,
            'event' => json_encode($this->event),
        ];
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $postFields);
        // Always return instead of outputting response!
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        if ($curlErrno) {
            $this->logger->critical(
                'Curl error: ' . curl_error($ch),
                compact('curlErrno', 'response', 'postFields')
            );
        } else {
            $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $this->logger->log(
                $httpCode === 200 ? Log\LogLevel::INFO : Log\LogLevel::ERROR,
                'Amplitude HTTP API response: ' . $response,
                compact('httpCode', 'response', 'postFields')
            );
        }
        curl_close($ch);
    }
}
