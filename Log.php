<?php

namespace zvsv\logger;

use app\models\helpers\IssetVal;
use app\models\helpers\Support;
use yardum\helpers\Files;
use Yii;
use yii\db\Expression;

class Log
{	
	public $files = [];
	
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
		
        if(
            isset(Yii::$app->params['currentController']) && Yii::$app->params['currentController']
            &&
            isset(Yii::$app->params['currentAction']) && Yii::$app->params['currentAction']
        ) {
            $files[] = '/logs/ca/' . Yii::$app->params['currentController'] . '/' . Yii::$app->params['currentAction'];
        } else {
            $files[] = '/logs/default';
        }

        //$this->writeInDb($file, $content, $params);

        foreach ($files AS $item_file){
            $this->writeInFile($item_file, $content, $params);
        }
    }

    /**
     * Пишем в базу
     * @param $file
     * @param $content
     * @param $params
     */
    /*protected function writeInDb($file, $content, $params) {
        Yii::$app->db->createCommand()->insert('{{%logs}}', [
            'file' => $file ? ltrim(substr($file, 0, 5) === '/logs' ? substr($file, 5) : $file, '/') : NULL,
            'user_id' => isset(Yii::$app->user->id) ? Yii::$app->user->id : NULL,
            'controller' => isset(Yii::$app->params['currentController']) ? Yii::$app->params['currentController'] : NULL,
            'action' => isset(Yii::$app->params['currentAction']) ? Yii::$app->params['currentAction'] : NULL,
            'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'date' => new Expression('NOW()'),
        ])->execute();
    }*/

    /**
     * Пишем в файл
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

            //Support::customMailES($emails, $theme, $message, $callFile, $callLine);
            Support::customLimitedMail($emails, $theme, $message, $callFile, $callLine);
        }
    }
	
	public static function newFilePath($file_path, $only_file){
		//Надо переименовать файл, добавив ему время
		$only_file = $only_file[0].'-'.date("YmdHis", time()).(isset($only_file[1]) ? '.'.$only_file[1] : '');
		return $file_path.$only_file.'.log';
	}
}