<?php

namespace zvsv\logger;

use app\models\helpers\IssetVal;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\helpers\FileHelper;

class Log
{	
	public $files = [];

    /**
     * Пишем логи в базу
     * @param $file
     * @param $content
     * @param $params
     */
    protected function writeInDb($file, $content, $params) {
        //Если таблицы не существует, надо создать
        if (\Yii::$app->db->getTableSchema('{{%logs}}', true) === null) {
            Yii::$app->db->createCommand()->setSql("
                CREATE TABLE {{%logs}} (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `file` varchar(250) DEFAULT NULL,
                  `host` varchar(250) DEFAULT NULL,
                  `users_id` text,
                  `controller` varchar(100) DEFAULT NULL,
                  `action` varchar(100) DEFAULT NULL,
                  `content` text,
                  `params` text,
                  `date_add` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата добавления',
                  `date_update` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата последнего добавления',
                  `hash` varchar(32) DEFAULT NULL COMMENT 'Контрольная сумма',
                  `call_count` int(11) DEFAULT NULL COMMENT 'Кол-во вызовов с одинаковой контрольной суммой',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq` (`hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ")->execute();
        }

        $host = Yii::$app->request->serverName;
        $user_id = isset(Yii::$app->user->id) ? Yii::$app->user->id : 0;
        $currentController = isset(Yii::$app->params['currentController']) ? Yii::$app->params['currentController'] : NULL;
        $currentAction = isset(Yii::$app->params['currentAction']) ? Yii::$app->params['currentAction'] : NULL;
        $hash = md5($host.$currentController.$currentAction.$file.print_r($content, true));

        $insert = Yii::$app->db->createCommand()->insert('{{%logs}}', [
            'file' => $file ? $file : NULL,
            'host' => $host,
            'controller' => $currentController,
            'action' => $currentAction,
            'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'date_add' => new Expression('NOW()'),
            'hash' => $hash,
            'users_id' => ','.$user_id, //Сбор всех id пользователей
            'call_count' => 1
        ])->getRawSql();
        $insert .= " ON DUPLICATE KEY UPDATE 
                `date_update` = NOW(), `users_id` = concat(`users_id`, ',".$user_id."'), call_count = call_count + 1;";

        Yii::$app->db->createCommand()->setSql($insert)->execute();

        //Отправка Email в случае если это надо
        if( $send_email = IssetVal::set($params, 'send_email', false) ) {
            $emails = [];

            if(is_array($send_email)){
                $types_send = $send_email;
            } else if($send_email === true){
                $types_send = ['admin_log'];
            } else {
                return false;
            }

            foreach($types_send AS $item){
                if(is_array(Yii::$app->params['emails'][$item])) {
                    $emails = array_merge($emails, Yii::$app->params['emails'][$item]);
                } else {
                    $emails[] = Yii::$app->params['emails'][$item];
                }
            }

            if(!$file) {
                $file = 'Файл не указан';
            }
            $theme = 'Logger: ' . $file;
            $message = "Логгером было добавлено новое сообщение. Логгер был вызван с параметром дублирования сообщения на почту\n\n";
            $message .= 'Домен: ' . Yii::$app->request->serverName . "\n";
            $message .= 'Страница: ' . Yii::$app->request->url . "\n";
            $message .= 'Referer: ' . Yii::$app->request->referrer . "\n\n";
            $callFile = isset($params['call_file']) ? $params['call_file'] : '';
            $callLine = isset($params['call_line']) ? $params['call_line'] : '';
            if($callFile) {
                $message .= 'File: ' . $callFile . "\n";
            }
            if($callLine) {
                $message .= 'Line: ' . $callLine . "\n";
            }
            if($callFile or $callLine) {
                $message .= "\n";
            }
            $message .= print_r($content, true);

            self::customLimitedMail($emails, $theme, $message, $hash);
        }
    }
	
	/**
	 * Логи в любую папку относительно приложения
	 * 
	 * @param string $file - относительный путь к файлу лога
	 * @param mix data (array, object, string, integer) $content - содержимое лога
	 * @param array $params = [
	 *		'max_file_size' - максимальный рамер файла в МБ
	 * ]
	 */
    public function write($file, $content, $params=[]){
        $files = [];
        if($file){
            $files[] = $file;
        }

		$trace = debug_backtrace();
		$call = isset($trace[0]) ? $trace[0] : '';
		$callFile = isset($call['file']) ? $call['file'] : '';
		$callLine = isset($call['line']) ? $call['line'] : 0;
		$params = array_merge($params, ['call_file' => $callFile, 'call_line' => $callLine]);

		//Если есть возможность определить текущий контроллер,
        //если в контороллере заданы поведения определяющие эти параметры (GetActionInfo)
        if(
            isset(Yii::$app->params['currentController']) && Yii::$app->params['currentController']
            &&
            isset(Yii::$app->params['currentAction']) && Yii::$app->params['currentAction']
        ) {
            $files[] = '/logs/ca/' . Yii::$app->params['currentController'] . '/' . Yii::$app->params['currentAction'];
        }

        //Для изучения хронологии вызовов
        $files[] = '/logs/default';

        $this->writeInDb($file, $content, $params);

        foreach ($files AS $item_file){
            $this->writeInFile($item_file, $content, $params);
        }
    }

    /**
     * Пишем лог в файл
     *
     * @param $file
     * @param $content
     * @param $params
     */
    protected function writeInFile($file, $content, $params){
        $size = isset($params['max_file_size']) ? $params['max_file_size'] : 10 ;

        $file = Yii::getAlias('@app').$file;

        $file_path_arr = explode('/', $file);
        $only_file = $file_path_arr[count($file_path_arr)-1];
        $file_path = str_replace($only_file, '', $file);
        $only_file = explode('.', $only_file);

        FileHelper::createDirectory($file_path);

        //Берем последний файл
        $file = glob("{$file_path}{$only_file[0]}-[0-9]*.log");
        $file = isset($file[count($file)-1]) ? $file[count($file)-1] : FALSE ;

        //Файлов еще нет (проверка нормальная, проверял)))
        if(!$file){
            $file = self::newFilePath($file_path, $only_file);
        } else if($size && filesize($file) > ($size * 1024 * 1024)) {
            $file = self::newFilePath($file_path, $only_file);
        }

        if(!file_exists($file)){
            $fp = fopen($file, "w");
            chmod($file, 0755);
        } else {
            $fp = fopen($file, "a");
        }

        $this->files[$file] = isset($this->files[$file]);

        $content = print_r($content, true);

        $content = !$this->files[$file] ? "\n\n===[".gmdate("Y-m-d  H:i:s", time())."]\n   ---".$content:
            "\n   ---".$content;

        fwrite($fp, $content);
		fclose($fp);
    }

    /**
     * Отправляет произвольное сообщение через SMTP с контролем количества отправленных аналогичных сообщений за
     * указанный интервал времени. При превышении допустимого количества - сообщения отправляться не будут. Аналогичными
     * считаются сообщения с одинаковыми параметрами $file и $line (название файла и номер строки, повлекшие отправку)
     * @param mixed $emails - адреса, на которые нужно отправить письмо (строка или массив строк)
     * @param string $theme - тема сообщения
     * @param string $message - текст сообщения
     * @param string $hash - контрольная сумма письма
     * @param boolean $html - формат отправляемого письма - html или простой текст
     * @param integer $periodQty - количество данных писем, которое разрешается отправить за выбранный интервал времени
     * @param integer $periodTime - интервал времени (в секундах)
     * @throws Exception
     */
    public static function customLimitedMail($emails, $theme, $message, $hash, $html = false, $periodQty = 5, $periodTime = 7200) {
        if(is_string($emails)) {
            $emails = [$emails];
        }
        if(!is_array($emails) or !is_string($theme) or !is_string($message)) {
            throw new Exception('Wrong arguments!');
        }
        $from = isset(Yii::$app->components['mailerSupport']['transport']['username']) ? Yii::$app->components['mailerSupport']['transport']['username'] : 'rassylschik.logov@gmail.com';
        $check = self::checkPeriod($hash, $periodTime, $from);

        if(($check['qty']-1) >= $periodQty) {
            return false;
        }
        if(($check['qty']-1) >= ($periodQty - 1)) {
            $message .= ($html ? '<br><br>' : "\n\n");
            $message .= 'Превышен лимит отправляемых сообщений для данной комбинации `'.$hash.'`. Первое отправлено: ' . $check['first'] . ', всего отправлено (включая данное): ' . ($check['qty']);
        }
        try {
            \Yii::$app->mailerSupport->compose()
                ->setTo($emails)
                ->setFrom($from)
                ->setSubject($theme)
                ->setTextBody($message)
                ->send();
        } catch(\Exception $e) {
            self::errorDuringErrorHandling($e, $from);
        }
    }

    /**
     * Проверка чтоб не было частых отправкок одних и тех же сообщений (для случаев если баг начинает сппимит в логах)
     */
    public static function checkPeriod($hash, $periodTime, $from = '') {

        try {
            $equals = (new Query)->select('*')->from('{{%logs}}')->where([
                'and',
                ['hash' => $hash],
                ['>=', 'date_update', new Expression('DATE_SUB(NOW(), INTERVAL ' . $periodTime . ' SECOND)')]
            ])->one();
            $qty = $equals['call_count'];
            $first = $equals['date_add'];

            $answer = ['qty' => $qty, 'first' => $first];
        } catch(\Exception $e) {
            self::errorDuringErrorHandling($e, $from);
            $answer = false;
        }
        return $answer;
    }

    /**
     * Создание нового пути файла
     *
     * @param $file_path
     * @param $only_file
     * @return string
     */
	public static function newFilePath($file_path, $only_file){
		//Надо переименовать файл, добавив ему время
		$only_file = $only_file[0].'-'.date("YmdHis", time()).(isset($only_file[1]) ? '.'.$only_file[1] : '');
		return $file_path.$only_file.'.log';
	}

    public static function errorDuringErrorHandling($e, $from = '') {
        $str = '==================== ' . date('d.m.Y, H:i:s', time() + 3600 * 3) . " ==============================\n";
        $str .= "Произошла ошибка при попытке отправить сообщение об ошибке!\n";
        $str .= "Отправляю с ящика: <{$from}>\n";
        $str .= $e->getCode() . ': ' . $e->getMessage() . "\n";
        $str .= $e->getFile() . ': ' . $e->getLine() . "\n\n";
        file_put_contents(Yii::getAlias('@app') . '/logs/ErrorDuringSendingError.log', $str, FILE_APPEND);
    }
}