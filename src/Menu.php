<?php

namespace yiidreamteam\wizard;

use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\helpers\ArrayHelper;
use yii\web\Controller;


/**
 * @author Valentine Konusov <rlng-krsk@yandex.ru>
 */
class Menu extends Widget
{

    /** @var array */
    public $options = [
        'activeCssClass' => 'active',
        'firstItemCssClass' => 'first',
        'lastItemCssClass' => 'last',
        'previousItemsCssClass' => 'previous'
    ];

    public $items;

    /**
     * @property string If not empty, this is added to the menu as the last item.
     * Used to add the conclusion, i.e. what happens when the wizard completes -
     * e.g. Register, to a menu.
     */
    public $menuLastItem;

    /** @var array Step labels */
    private $stepLabels;

    /** @var Behavior Wizard behavior class */
    protected $wizard;

    /** @var Controller */
    private $controller;

    /** @var Object */
    protected $widget;

    public $menuConfig = [];

    public function init()
    {
        $this->controller = $this->getView()->context;

        foreach ($this->controller->getBehaviors() as $behavior) {
            if ($behavior instanceof Behavior)
                $this->wizard = $behavior;
        }
        if (!$this->wizard instanceof Behavior)
            throw new InvalidConfigException(\Yii::t('wizard', 'Behavior '.__NAMESPACE__.'\Behavior not found at Controller'));

        $defaultConfig = [];
        $defaultConfig['class'] = '\yii\bootstrap\Nav';
        $defaultConfig['items'] = $this->generateMenuItems();

        $this->widget = \Yii::createObject(ArrayHelper::merge($defaultConfig, $this->menuConfig));

        parent::init();
    }

    public function run()
    {
        $this->widget->run();
    }

    private function generateMenuItems()
    {
        $previous = true;
        $items = [];
        $url = [$this->controller->id . '/' . $this->controller->action->id];
        $parsedSteps = $this->wizard->getParsedSteps();
        $this->stepLabels = array_flip($parsedSteps);

        foreach ($parsedSteps as $step) {
            $item = [];
            $item['label'] = $this->getStepLabel($step);
            if (($previous && !$this->wizard->forwardOnly) || ($step === $this->wizard->getCurrentStep())) {
                $item['url'] = $url + [$this->wizard->queryParam => $step];
                if ($step === $this->wizard->getCurrentStep())
                    $previous = false;
            }
            $item['active'] = $step === $this->wizard->getCurrentStep();
            if ($previous && !empty($this->options['previousItemCssClass']))
                $item['itemOptions'] = array('class' => $this->options['previousItemCssClass']);

            $items[] = $item;
        }
        if (!empty($this->menuLastItem))
            $items[] = array(
                'label' => $this->menuLastItem,
                'active' => false
            );
        return $items;
    }

    private function getStepLabel($step)
    {
        if (is_null($step))
            $step = $this->wizard->getCurrentStep();

        $label = $this->stepLabels[$step];
        if (!is_string($label)) {
            $label = ucwords(trim(strtolower(str_replace(array('-', '_', '.'), ' ', preg_replace('/(?<![A-Z])[A-Z]/', ' \0', $step)))));;
        }

        return $label;
    }
}