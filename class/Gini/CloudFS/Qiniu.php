<?php
/**
* @file Qiniu.php
* @brief 七牛云前代理
* @author PiHiZi
* @version 0.1.0
* @date 2014-07-11
 */

namespace Gini\CloudFS;

require_once(APP_PATH.'/vendor/qiniu/php-sdk/qiniu/rs.php');
require_once(APP_PATH.'/vendor/qiniu/php-sdk/qiniu/io.php');
require_once(APP_PATH.'/vendor/qiniu/php-sdk/qiniu/fop.php');

class Qiniu extends \Gini\CloudFS\Cloud
{
    private $_config = [];
    private $_client;
    public function __construct($client, $config)
    {
        $this->_client = $client;
        $this->_config = $config;
        $this->getRPC('cloudfs', $this->_config['rpc'])->qiniu->init($this->_config['rpc']['server']);
    }

    private function _getFilename()
    {
        $host = $_SERVER['HTTP_HOST'] ?: $_SERVER['SERVER_NAME'];
        $filename = $this->getRPC('cloudfs')->qiniu->getURI($host);
        return $filename;
    }

    private function _getToken($filename, $cbkURL=null, $cbkBody=null)
    {
        $options = $this->_config['options'];
        $token = $this->getRPC('cloudfs')->qiniu->getKeys([
            'file'=> $filename
            ,'callback_url'=> $cbkURL ?: $options['callback_url']
            ,'callback_body'=> $cbkBody ?: $options['callback_body']
        ]);
        return $token;
    }

    private function _filterResult($data, $error)
    {
        $result = false;
        $callbacks = (array)$this->_config['callbacks'];
        if ($error) {
            if (isset($callbacks['fail'])) {
                $result = call_user_func($callbacks['fail'], $error);
            }
        }
        else {
            if (isset($callbacks['success'])) {
                $result = call_user_func($callbacks['success'], $data);
            }
            else {
                $result = $data;
            }
        }
        
        if (isset($callbacks['always'])) {
            $result = call_user_func($callbacks['always'], [$data, $error]);
        }
        return $result;
    }

    public function upload($file)
    {
        $result = false;

        $file = $file['tmp_name'];
        if (!$file) return $result;
        
        $filename = $this->_getFilename();
        $token = $this->_getToken($filename);
        $content = file_get_contents($file);
        list($ret, $err) = \Qiniu_Put($token, $filename, $content, null);

        $result = $this->_filterResult($ret, $err);

        return $result;
    }

    public function isFromQiniuServer()
    {
        $authstr = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($authstr, 'QBox ')!=0) {
            return false;
        }
        $auth = explode(':', substr($authstr, 5));
        if (sizeof($auth)!=2) return false;
        $data = $_SERVER['REQUEST_URI'] . "\n" . file_get_contents('php://input');

        $result = $this->getRPC('cloudfs')->qiniu->isFromQiniuServer($data, $auth[0], $auth[1]);
        return !!$result;
    }

    public function runServerCallback(array $data)
    {
        $error = ($data['key'] && $data['hash']) ? false : new \Qiniu_Error(0, 'Response error from qiniu server.');
        $result = $this->_filterResult($data, $error);
        return $result;
    }

    public function getImageURL($filename, $hash=null)
    {
        $url = $this->getRPC('cloudfs')->qiniu->getImageURL($filename, $hash);
        return $url;
    }

    public function getUploadConfig()
    {
        $config = $this->_config;
        $options = $config['options'];

        $data = [];
        $params = [];

        if (isset($options['callback_url'])) {
            $params['x:callbackUrl'] = $options['callback_url'];
        }

        if (isset($options['callback_body'])) {
            $params['x:callbackBody'] = $options['callback_body'];
        }

        if ($options['mode']==='direct') {
            $data['url'] = 'http://up.qiniu.com';

            $filename = $this->_getFilename();
            $keys = $this->_getToken($filename);

            $params['key'] = $filename;
            $params['token'] = $keys;
        }
        else {
            $data['url'] = '/ajax/cloudfs/qiniu/upload/' . $this->_client;
        }

        $data['params'] = $params;

        return $data;
    }

    public function parseData(array $data=[]) 
    {
        if (!isset($data['key'])) return;
        if (!isset($data['hash'])) return;
        $image = $this->getImageURL($data['key'], $data['hash']);

        $options = $this->_config['options'];
        if ($options['image_view']) {
            $image .=  '?' . $options['image_view'] . '&_=' . time();
        }
        else {
            $image .=  '?' . time();
        }
        return $image;
    }

}
