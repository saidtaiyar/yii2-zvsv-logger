<?php
namespace zvsv\logger;

use yii\base\ActionFilter;

class GetActionInfo extends ActionFilter {

	public function beforeAction($action) {
		\Yii::$app->params['currentController'] = (isset($action->controller) and $action->controller) ? basename(str_replace('\\', '/', $action->controller->className())) : 'UnknownController';
		\Yii::$app->params['currentAction'] = $action->actionMethod;
		return true;
	}
}