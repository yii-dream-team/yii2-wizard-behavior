<?php
/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 */

namespace yiidreamteam\wizard;

use Yii;
use yii\base\Controller;
use yii\base\InvalidConfigException;
use yii\web\Session;

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
     * basic example: array('login_info', 'profile', 'confirm')
     *
     * Steps can be labled: array('Username and Password'=>'login_info', 'User Profile'=>'profile', 'confirm')
     *
     * The steps array can also contain branch groups that are used to determine
     * the path at runtime.
     * plot-branched example: array('job_application', array('degree' => array('college', 'degree_type'), 'nodegree' => 'experience'), 'confirm');
     *
     * The 'branch names' (ie 'degree', 'nodegree') are arbitrary; they are used as
     * selectors for the branch() method. Branches can point either to another
     * steps array, that can also have branch groups, or a single step.
     *
     * The first "non-skipped" branch in a group (see branch()) is used by default
     * if $defaultBranch==TRUE and a branch has not been specifically selected.
     */
    public $steps = array();
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
    /**
     * @property string Query parameter for the step. This must match the name
     * of the parameter in the action that calls the wizard.
     */
    public $queryParam ='step';
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
     *
     */
    public $draftSavedUrl = '/';
    /**
     * @property array Menu properties. In addition to the properties of CMenu
     * there is an additional previousItemCssClass that is applied to previous items.
     * @see getMenu()
     */
    public $menuProperties = array(
        'id'=>'wzd-menu',
        'activeCssClass'=>'wzd-active',
        'firstItemCssClass'=>'wzd-first',
        'lastItemCssClass'=>'wzd-last',
        'previousItemCssClass'=>'wzd-previous'
    );
    /**
     * @property string If not empty, this is added to the menu as the last item.
     * Used to add the conclusion, i.e. what happens when the wizard completes -
     * e.g. Register, to a menu.
     */
    public $menuLastItem;

    /**
     * @var string Internal step tracking.
     */
    private $currentStep;

    /**
     * @var object The menu.
     */
    private $menu;

    /**
     * @var array Step Labels.
     */
    private $stepLabels;

    /**
     * @var string The session key that holds processed step data.
     */
    private $stepsKey;

    /**
     * @var string The session key that holds branch directives.
     */
    private $branchKey;

    /**
     * @var string The session key that holds the timeout value.
     */
    private $timeoutKey;

    /** @var Session */
    private $session;

    /**
     * @property array Owner event handlers
     */
    public $events = array(
        Event::WIZARD_START => 'wizardStart',
        Event::WIZARD_FINISHED => 'wizardFinished',
        Event::PROCESS_STEP => 'wizardProcessStep',
        EVENT::INVALID_STEP => 'wizardInvalidStep'
    );

    /**
     * Attaches this behavior to the owner.
     * In addition to the CBehavior default implementation, the owner's event
     * handlers for wizard events are also attached.
     * @param \yii\web\Controller $owner
     * @throws \BadMethodCallException
     * @throws InvalidConfigException
     */
    public function attach($owner)
    {
        if (!$owner instanceof Controller)
            throw new \BadMethodCallException(Yii::t('wizard', 'Owner must be an instance of \yii\web\Controller'));

        parent::attach($owner);

        foreach ($this->events as $eventName => $handler) {
            if(!method_exists($owner, $handler))
                throw new InvalidConfigException(Yii::t('wizard', "Method {$handler} is missing"));

            $owner->on($eventName, [$owner, $handler]);
        }

        $this->session = Yii::$app->getSession();
        $this->stepsKey = $this->sessionKey . '.steps';
        $this->branchKey = $this->sessionKey . '.branches';
        $this->timeoutKey = $this->sessionKey . '.timeout';

        $this->parseSteps();
    }

    /**
     * Parse the steps into a flat array and get their labels
     */
    protected function parseSteps() {
        $this->steps = $this->parseStepsInternal($this->steps);
        $this->stepLabels = array_flip($this->steps);
    }

    /**
     * Parses the steps array into a "flat" array by resolving branches.
     * Branches are resolved according the setting
     * @param array $steps The steps array.
     * @return array Steps to take
     */
    private function parseStepsInternal($steps) {
        $parsed = [];

        foreach ($steps as $label=>$step) {
            $branch = '';
            if (is_array($step)) {
                foreach (array_keys($step) as $branchName) {
                    $branchDirective = $this->branchDirective($branchName);
                    if (
                        ($branchDirective && $branchDirective===self::BRANCH_SELECT) ||
                        (empty($branch) && $this->defaultBranch)
                    )
                        $branch = $branchName;
                }

                if (!empty($branch)) {
                    if (is_array($step[$branch]))
                        $parsed = array_merge($parsed, $this->parseStepsInternal($step[$branch]));
                    else
                        $parsed[$label] = $step[$branch];
                }
            }
            else
                $parsed[$label] = $step;
        }
        return $parsed;
    }

    /**
     * Returns the branch directive.
     * @param $branch
     * @return string|null the branch directive or NULL if no directive for the branch
     */
    private function branchDirective($branch) {
        return isset($this->session[$this->branchKey])
            ? $this->session[$this->branchKey][$branch]
            : null;
    }
}