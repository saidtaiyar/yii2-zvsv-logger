<?php

namespace zvsv\logger;

use app\models\helpers\IssetVal;
use app\models\helpers\Support;
use Yii;
use yii\db\Expression;
use yii\db\Query;

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
            Yii::$app->db->createCommand()->execute("
                CREATE TABLE `{{%logs}}` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `file` varchar(250) DEFAULT NULL,
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
                ) ENGINE=InnoDB AUTO_INCREMENT=51996 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;
            ");
        }

        $user_id = isset(Yii::$app->user->id) ? Yii::$app->user->id : NULL;

        echo Yii::$app->db->createCommand()->insert('{{%logs}}', [
            'file' => $file ? ltrim(substr($file, 0, 5) === '/logs' ? substr($file, 5) : $file, '/') : NULL,
            'controller' => isset(Yii::$app->params['currentController']) ? Yii::$app->params['currentController'] : NULL,
            'action' => isset(Yii::$app->params['currentAction']) ? Yii::$app->params['currentAction'] : NULL,
            'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'date' => new Expression('NOW()'),
            'hash' => md5($file.print_r($content, true)),
            'users_id' => ','.$user_id //Сбор всех id пользователей
        ])->getRawSql(); exit;

            //->execute();

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

            Support::customLimitedMail($emails, $theme, $message, $callFile, $callLine);
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
        Files::createPath($file, true, true);

        $file_path_arr = explode('/', $file);
        $only_file = $file_path_arr[count($file_path_arr)-1];
        $file_path = str_replace($only_file, '', $file);
        $only_file = explode('.', $only_file);

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
     * @param string $file - название файла, повлекшего отправку сообщения
     * @param integer $line - номер строки, на которой произошло событие (например вызов функции или ошибка), повлекшее
     *                        отправку сообщения
     * @param boolean $html - формат отправляемого письма - html или простой текст
     * @param integer $periodQty - количество данных писем, которое разрешается отправить за выбранный интервал времени
     * @param integer $periodTime - интервал времени (в секундах)
     * @throws Exception
     */
    public function customLimitedMail($emails, $theme, $message, $file = '', $line = 0, $html = false, $periodQty = 5, $periodTime = 7200) {
        if(is_string($emails)) {
            $emails = [$emails];
        }
        if(!is_array($emails) or !is_string($theme) or !is_string($message)) {
            throw new Exception('Wrong arguments!');
        }
        $from = isset(Yii::$app->components['mailerSupport']['transport']['username']) ? Yii::$app->components['mailerSupport']['transport']['username'] : 'rassylschik.logov@gmail.com';
        $check = self::checkPeriod($file, $line, $periodQty, $periodTime, $from);
        if($check['qty'] >= $periodQty) {
            return false;
        }
        if($check['qty'] >= ($periodQty - 1)) {
            $message .= ($html ? '<br><br>' : "\n\n");
            $message .= 'Превышен лимит отправляемых сообщений для данной комбинации файл + строка (' . $file . ':' .
                $line . ')! Первое отправлено: ' . $check['first'] . ', всего отправлено (включая данное): ' . ($check['qty'] + 1);
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

    public static function checkPeriod($file, $line, $periodQty, $periodTime, $from = '') {
        if(empty($file) or $line < 1) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $call = isset($trace[1]) ? $trace[1] : [];
            $file = isset($call['file']) ? $call['file'] : '';
            $line = isset($call['line']) ? $call['line'] : '';
        }
        $file = trim(str_replace(Yii::getAlias('@app/'), '', $file));
        try {
            $equals = (new Query)->select(['send'])->from('{{%logs}}')->where([
                'and',
                ['line' => $line],
                ['file' => $file],
                ['>=', 'send', new Expression('DATE_SUB(NOW(), INTERVAL ' . $periodTime . ' SECOND)')]
            ])->column();
            $qty = count($equals);
            $first = reset($equals);
            if($qty < $periodQty) {
                (new Query)->createCommand()->insert('{{%logs}}', [
                    'line' => $line,
                    'file' => $file,
                    'send' => new Expression('NOW()')
                ])->execute();
            }
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
}