<?php
/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 */

namespace yiidreamteam\wizard;

/**
 * Wizard event class.
 * This is the event raised by the wizard.
 *
 * @property Behavior $sender
 */
class Event extends \yii\base\Event
{
    const WIZARD_START = 'wizardStart';
    const WIZARD_FINISHED = 'wizardFinished';
    const WIZARD_PROCESS_STEP = 'wizardProcessStep';
    const WIZARD_INVALID_STEP = 'wizardInvalidStep';

    const WIZARD_RESET = 'wizardReset';
    const WIZARD_CANCEL = 'wizardCancel';
    const WIZARD_EXPIRED = 'wizardExpired';
    const WIZARD_SAVE_DRAFT = 'wizardSaveDraft';

    public $data = [];
    public $step;

    /**
     * Event factory
     *
     * @param \yii\base\Object $sender
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