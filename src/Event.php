<?php
/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 */

namespace yiidreamteam\wizard;

/**
 * Wizard event class.
 * This is the event raised by the wizard.
 */
class Event extends \yii\base\Event
{
    const START = 'wizardStart';
    const FINISHED = 'wizardFinished';
    const PROCESS_STEP = 'wizardProcessStep';
    const INVALID_STEP = 'wizardInvalidStep';

    public $data = [];
    public $step;

    /**
     * Event factory
     *
     * @param Object $sender
     * @param string|null $step
     * @param array $data
     * @return Event
     */
    public static function create($sender, $step = null, $data = null)
    {
        return new static([
            'sender' => $sender,
            'step' => $step,
            'data' => $data
        ]);
    }
}