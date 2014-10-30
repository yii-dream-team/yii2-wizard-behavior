<?php
/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 */

namespace yiidreamteam\wizard;

use yii\base\InvalidConfigException;
use yii\web\Controller;
use yii\web\Session;

/**
 * Wizard Behavior class
 *
 * @property Controller $owner
 */
class Behavior extends \yii\base\Behavior
{
    const BRANCH_SELECT = 'Select';
    const BRANCH_SKIP = 'Skip';
    const BRANCH_DESELECT = 'Deselect';

    /**
     * @property boolean If true, the behavior will redirect to the "expected step"
     * after a step has been successfully completed. If false, it will redirect to
     * the next step in the steps array.
     */
    public $autoAdvance = true;

    /**
     * @property array List of steps, in order, that are to be included in the wizard.
     * basic example: ['login_info', 'profile', 'confirm']
     *
     * Steps can be labelled: ['Username and Password'=>'login_info', 'User Profile'=>'profile', 'confirm']
     *
     * The steps array can also contain branch groups that are used to determine
     * the path at runtime.
     * plot-branched example: ['job_application', ['degree' => ['college', 'degree_type'], 'nodegree' => 'experience'], 'confirm'];
     *
     * The 'branch names' (ie 'degree', 'nodegree') are arbitrary; they are used as
     * selectors for the branch() method. Branches can point either to another
     * steps array, that can also have branch groups, or a single step.
     *
     * The first "non-skipped" branch in a group (see branch()) is used by default
     * if $defaultBranch == true and a branch has not been specifically selected.
     */
    public $steps = [];

    /**
     * @property boolean If true, the first "non-skipped" branch in a group will be
     * used if a branch has not been specifically selected.
     */
    public $defaultBranch = true;

    /**
     * @property boolean Whether the wizard should go to the next step if the
     * current step expires. If true the wizard continues, if false the wizard is
     * reset and the redirects to the expiredUrl.
     */
    public $continueOnExpired = false;

    /**
     * @property boolean If true, the user will not be allowed to edit previously
     * completed steps.
     */
    public $forwardOnly = false;

    /** @var array Owner event handlers */
    public $events = [
        Event::WIZARD_START => 'wizardStart',
        Event::WIZARD_PROCESS_STEP => 'wizardProcessStep',
        Event::WIZARD_FINISHED => 'wizardFinished',
        Event::WIZARD_INVALID_STEP => 'wizardInvalidStep'
    ];

    /**
     * @property string Query parameter for the step. This must match the name
     * of the parameter in the action that calls the wizard.
     */
    public $queryParam = 'step';

    /**
     * @property string The session key for the wizard.
     */
    public $sessionKey = 'Wizard';

    /**
     * @property integer The timeout in seconds. Set to empty for no timeout.
     * Each step must be completed within the timeout period or else the wizard expires.
     */
    public $timeout;

    /**
     * @property string The name attribute of the button used to cancel the wizard.
     */
    public $cancelButton = 'cancel';

    /**
     * @property string The name attribute of the button used to navigate to the previous step.
     */
    public $previousButton = 'previous';

    /**
     * @property string The name attribute of the button used to reset the wizard
     * and start from the beginning.
     */
    public $resetButton = 'reset';

    /**
     * @property string The name attribute of the button used to save draft data.
     */
    public $saveDraftButton = 'save_draft';

    /**
     * @property mixed Url to be redirected to after the wizard has finished.
     */
    public $finishedUrl = '/';

    /**
     * @property mixed Url to be redirected to after 'Cancel' submit button has been pressed by user.
     */
    public $cancelledUrl = '/';

    /**
     * @property mixed Url to be redirected to if the timeout expires.
     */
    public $expiredUrl = '/';

    /**
     * @property mixed Url to be redirected to after 'Draft' submit button has been pressed by user.
     */
    public $draftSavedUrl = '/';

    /** @var string Internal step tracking. */
    private $_currentStep;
    /** @var array The steps to be processed. */
    private $_steps;
    /** @var array Step labels */
    private $_stepLabels;
    /** @var string The session key that holds processed step data. */
    private $_stepsKey;
    /** @var string The session key that holds branch directives. */
    private $_branchKey;
    /** @var string The session key that holds the timeout value. */
    private $_timeoutKey;
    /** @var Session */
    private $_session;

    /**
     * Attaches this behavior to the owner.
     * In addition to the \yii\base\Behavior default implementation,
     * the owner's event handlers for wizard events are also attached.
     *
     * @param Controller $owner The controller that this behavior is to be attached to.
     * @throws InvalidConfigException
     */
    public function attach($owner)
    {
        if (!$owner instanceof Controller)
            throw new InvalidConfigException(\Yii::t('wizard', 'Owner must be an instance of yii\web\Controller'));

        parent::attach($owner);

        foreach ($this->events as $event => $handler) {
            if (!method_exists($owner, $handler))
                throw new InvalidConfigException(\Yii::t('wizard', 'Wizard method is missing: {0}', $handler));
            $owner->on($event, [$owner, $handler]);
        }

        $this->_session = \Yii::$app->getSession();
        $this->_stepsKey = $this->sessionKey . '.steps';
        $this->_branchKey = $this->sessionKey . '.branches';
        $this->_timeoutKey = $this->sessionKey . '.timeout';

        $this->parseSteps();
    }

    /**
     * Run the wizard for the given step.
     * This method is called from the controller action using the wizard
     * @param string $step Name of step to be processed.
     */
    public function process($step)
    {
        if (isset($_REQUEST[$this->cancelButton]))
            $this->cancelled($step); // Ends the wizard
        elseif (isset($_REQUEST[$this->resetButton]) && !$this->forwardOnly) {
            $this->resetWizard($step); // Restarts the wizard
            $step = null;
        }

        if (empty($step)) {
            if (!$this->hasStarted() && !$this->start())
                $this->finished(false);
            if ($this->hasCompleted())
                $this->finished(true);
            else $this->nextStep();
        } else {
            if ($this->isValidStep($step)) {
                $this->_currentStep = $step;
                if (!$this->forwardOnly && isset($_REQUEST[$this->previousButton]))
                    $this->previousStep();
                elseif ($this->processStep()) {
                    if (isset($_REQUEST[$this->saveDraftButton]))
                        $this->saveDraft($step); // Ends the wizard
                    $this->nextStep();
                }
            } else $this->invalidStep($step);
        }
    }

    /**
     * Sets data into wizard session. Particularly useful if the data
     * originated from WizardComponent::read() as this will restore a previous session.
     * $data[0] is the step data, $data[1] the branch data, $data[2] is the timeout value.
     *
     * @param array $data Data to be written to the wizard session.
     * @return boolean Whether the data was successfully restored;
     * true if the data was successfully restored, false if not
     */
    public function restore($data)
    {
        if (sizeof($data) !== 3 || !is_array($data[0]) || !is_array($data[1]) || !(is_integer($data[2]) || is_null($data[2])))
            return false;
        $this->_session[$this->_stepsKey] = $data[0];
        $this->_session[$this->_branchKey] = $data[1];
        $this->_session[$this->_timeoutKey] = $data[2];
        return true;
    }

    /**
     * Saves data into the Session.
     * This is normally called automatically after the Event::WIZARD_PROCESS_STEP event,
     * but can be called directly for advanced navigation purposes.
     *
     * @param mixed $data Data to be saved
     * @param string $step Step name. If empty the current step is used.
     */
    public function save($data, $step = null)
    {
        $this->_session[$this->_stepsKey][empty($step) ? $this->_currentStep : $step] = $data;
    }

    /**
     * Reads data stored for a step.
     * @param string|null $step The name of the step. If empty the data for all steps are returned.
     * @return mixed Data for the specified step;
     * array: data for all steps; null is no data exist for the specified step.
     */
    public function read($step = null)
    {
        return empty($step)
            ? $this->_session[$this->_stepsKey]
            : $this->_session[$this->_stepsKey][$step];
    }

    /**
     * Returns the one-based index of the current step.
     * Note that this is for the current steps; branching may vary the index of a given step
     */
    public function getCurrentStep()
    {
        return $this->getStepIndex($this->_currentStep) + 1;
    }

    /**
     * Returns the number of steps.
     * Note that this is for the current steps; branching may vary the number of steps
     *
     * @return integer
     */
    public function getStepCount()
    {
        return count($this->_steps);
    }

    /**
     * @param string|null $step
     * @return string
     */
    public function getStepLabel($step = null)
    {
        if ($step === null)
            $step = $this->_currentStep;

        if (!isset($this->_stepLabels[$step]))
            return ucwords(trim(strtolower(str_replace(['-', '_', '.'], ' ', preg_replace('/(?<![A-Z])[A-Z]/', ' \0', $step)))));

        return $this->_stepLabels[$step];
    }

    /**
     * Resets the wizard by deleting the wizard session.
     */
    public function reset()
    {
        $this->_session->remove($this->_branchKey);
        $this->_session->remove($this->_stepsKey);
        $this->_session->remove($this->_timeoutKey);
    }

    /**
     * Returns a value indicating if the step has expired
     * @return boolean True if the step has expired, false if not
     */
    protected function hasExpired()
    {
        return isset($this->_session[$this->_timeoutKey]) && $this->_session[$this->_timeoutKey] < time();
    }

    /**
     * Moves the wizard to the next step
     * If autoAdvance===true this will be the expectedStep,
     * if autoAdvance===false this will be the next step in the steps array
     */
    protected function nextStep()
    {
        if ($this->autoAdvance)
            $step = $this->getExpectedStep();
        else {
            $index = $this->getStepIndex($this->_currentStep) + 1;
            $step = $index < count($this->_steps) ? $this->_steps[$index] : null;
        }

        if ($this->timeout)
            $this->_session[$this->_timeoutKey] = time() + $this->timeout;

        $this->redirect($step);
    }

    /**
     * Moves the wizard to the previous step
     */
    protected function previousStep()
    {
        $index = $this->getStepIndex($this->_currentStep) - 1;
        $this->redirect($this->_steps[$index > 0 ? $index : 0]);
    }

    /**
     * Returns a value indicating if the wizard has started
     *
     * @return boolean True if the wizard has started, false if not
     */
    protected function hasStarted()
    {
        return isset($this->_session[$this->_stepsKey]);
    }

    /**
     * Returns a value indicating if the wizard has completed
     *
     * @return boolean True if the wizard has completed, false if not
     */
    protected function hasCompleted()
    {
        return !(bool)$this->getExpectedStep();
    }

    /**
     * Handles Wizard redirection. A null url will redirect to the "expected" step.
     *
     * @param string|null $step Step to redirect to.
     * @param boolean $terminate If true, the application terminates after the redirect
     * @param integer $statusCode HTTP status code (eg: 404)
     * @see \yii\web\Controller::redirect()
     */
    protected function redirect($step = null, $terminate = true, $statusCode = 302)
    {
        if (!is_string($step))
            $step = $this->getExpectedStep();

        $url = [$this->owner->id . '/' . $this->owner->action->id, $this->queryParam => $step];
        $this->owner->redirect($url, $statusCode);

        if ($terminate)
            \Yii::$app->end();
    }

    /**
     * Selects, skips, or deselects a branch or branches.
     *
     * @param mixed $branchDirectives Branch directives.
     * string: The branch name or a list of branch names to select
     * array: either an array of branch names to select or
     * an array of "branch name" => branchDirective pairs
     * branchDirective = [self::BRANCH_SELECT|self::BRANCH_SKIP|self::BRANCH_DESELECT|]
     */
    public function branch($branchDirectives)
    {
        if (is_string($branchDirectives)) {
            if (strpos($branchDirectives, ',')) {
                $branchDirectives = explode(',', $branchDirectives);
                foreach ($branchDirectives as &$name)
                    $name = trim($name);
            } else
                $branchDirectives = [$branchDirectives];
        }

        $branches = $this->branches();

        foreach ($branchDirectives as $name => $directive) {
            if ($directive === self::BRANCH_DESELECT)
                unset($branches[$name]);
            else {
                if (is_int($name)) {
                    $name = $directive;
                    $directive = self::BRANCH_SELECT;
                }
                $branches[$name] = $directive;
            }
        }
        $this->_session[$this->_branchKey] = $branches;
        $this->parseSteps();
    }

    /**
     * Returns an array of the current branch directives
     *
     * @return array An array of the current branch directives
     */
    private function branches()
    {
        return isset($this->_session[$this->_branchKey])
            ? $this->_session[$this->_branchKey]
            : [];
    }

    /**
     * Validates the $step in two ways:
     *   1. Validates that the step exists in $this->_steps array.
     *   2. Validates that the step is the expected step or,
     *      if forwardsOnly==false, before it.
     *
     * @param string $step Step to validate.
     * @return boolean Whether the step is valid; true if the step is valid,
     * false if not
     */
    protected function isValidStep($step)
    {
        $index = $this->getStepIndex($step);

        if ($index === false)
            return false;

        if ($this->forwardOnly)
            return $index === $this->getStepIndex($this->getExpectedStep());

        return $index <= $this->getStepIndex($this->getExpectedStep());
    }

    /**
     * Returns the first unprocessed step (i.e. step data not saved in Session).
     *
     * @return string|null The first unprocessed step; null if all steps have been processed
     */
    protected function getExpectedStep()
    {
        $steps = $this->_session[$this->_stepsKey];
        if (!is_null($steps)) {
            foreach ($this->_steps as $step) {
                if (!isset($steps[$step]))
                    return $step;
            }
        }
    }

    /**
     * Parse the steps into a flat array and get their labels
     */
    protected function parseSteps()
    {
        $this->_steps = $this->_parseSteps($this->steps);
        $this->_stepLabels = array_flip($this->_steps);
    }

    /**
     * Parses the steps array into a "flat" array by resolving branches.
     * Branches are resolved according the setting
     * @param array $steps The steps array.
     * @return array Steps to take
     */
    private function _parseSteps($steps)
    {
        $parsed = array();

        foreach ($steps as $label => $step) {
            $branch = '';
            if (is_array($step)) {
                foreach (array_keys($step) as $branchName) {
                    $branchDirective = $this->branchDirective($branchName);
                    if (
                        ($branchDirective && $branchDirective === self::BRANCH_SELECT) ||
                        (empty($branch) && $this->defaultBranch)
                    )
                        $branch = $branchName;
                }

                if (!empty($branch)) {
                    if (is_array($step[$branch]))
                        $parsed = array_merge($parsed, $this->_parseSteps($step[$branch]));
                    else
                        $parsed[$label] = $step[$branch];
                }
            } else
                $parsed[$label] = $step;
        }
        return $parsed;
    }

    /**
     * Returns the branch directive.
     *
     * @param string $branch
     * @return string the branch directive or NULL if no directive for the branch
     */
    private function branchDirective($branch)
    {
        return isset($this->_session[$this->_branchKey])
            ? $this->_session[$this->_branchKey][$branch]
            : null;
    }

    /**
     * Raises the Event::WIZARD_START event.
     * The event handler must set the event::handled property TRUE for the wizard
     * to process steps.
     */
    protected function start()
    {
        $event = Event::create($this);
        $this->owner->trigger(Event::WIZARD_START, $event);

        if ($event->handled)
            $this->_session[$this->_stepsKey] = [];

        return $event->handled;
    }

    /**
     * Raises the Event::WIZARD_CANCEL event.
     * The event::data property contains data for processed steps.
     *
     * @param string $step
     */
    protected function cancelled($step)
    {
        $event = Event::create($this, $step, $this->read());
        $this->owner->trigger(Event::WIZARD_CANCEL, $event);
        $this->reset();
        $this->owner->redirect($this->cancelledUrl);
    }

    /**
     * Raises the Event::WIZARD_EXPIRED event.
     */
    protected function expired($step)
    {
        $event = Event::create($this, $step);
        $this->owner->trigger(Event::WIZARD_EXPIRED, $event);
        if ($this->continueOnExpired)
            return true;
        $this->reset();
        $this->owner->redirect($this->expiredUrl);
    }

    /**
     * Raises the Event::WIZARD_FINISHED event.
     * The event::data property contains data for processed steps.
     *
     * @param string $step
     */
    protected function finished($step)
    {
        $event = Event::create($this, $step, $this->read());
        $this->owner->trigger(Event::WIZARD_FINISHED, $event);
        $this->reset();
        $this->owner->redirect($this->finishedUrl);
    }

    /**
     * Raises the Event::WIZARD_PROCESS_STEP event.
     *
     * @param string $step
     */
    protected function invalidStep($step)
    {
        $event = Event::create($this, $step);
        $this->owner->trigger(Event::WIZARD_INVALID_STEP, $event);
        $this->redirect();
    }

    /**
     * Raises the Event::WIZARD_PROCESS_STEP event.
     * The $event->data property contains the current data for the step.
     * The event handler must set the $event->handled property to "true"
     * for the wizard to move to the next step.
     */
    protected function processStep()
    {
        $event = Event::create($this, $this->_currentStep, $this->read($this->_currentStep));
        $this->owner->trigger(Event::WIZARD_PROCESS_STEP, $event);
        if ($event->handled && $this->hasExpired())
            $this->expired($this->_currentStep);
        return $event->handled;
    }

    /**
     * Resets the wizard by deleting the wizard session.
     * @param string $step
     */
    public function resetWizard($step)
    {
        $this->reset();
        $event = Event::create($this, $step);
        $this->owner->trigger(Event::WIZARD_RESET, $event);
    }

    /**
     * Raises the onSaveDraft event.
     * The event::data property contains the data to save.
     */
    protected function saveDraft($step)
    {
        $event = Event::create($this, $step, [
            $this->read(),
            $this->branches(),
            $this->_session[$this->_timeoutKey]
        ]);

        $this->owner->trigger(Event::WIZARD_SAVE_DRAFT, $event);

        $this->reset();
        $this->owner->redirect($this->draftSavedUrl);
    }

    /**
     * @param string $stepName
     * @return integer|false
     */
    protected function getStepIndex($stepName)
    {
        return array_search($stepName, $this->_steps);
    }

}
