<?php
/**
 * Action for VOID Plugin
 *
 * @author AlanDecode | 熊猫小A
 */
require_once 'libs/IP.php';
require_once 'libs/ParseAgent.php';

/**
 * 根据ID获取单个Widget对象
 *
 * @param string $table 表名, 支持 contents, comments, metas, users
 * @return Widget_Abstract
 */
function widgetById($table, $pkId)
{
    $table = ucfirst($table);
    if (!in_array($table, array('Contents', 'Comments', 'Metas', 'Users'))) {
        return NULL;
    }

    $keys = array(
        'Contents'  =>  'cid',
        'Comments'  =>  'coid',
        'Metas'     =>  'mid',
        'Users'     =>  'uid'
    );

    $className = "Widget_Abstract_{$table}";
    $key = $keys[$table];
    $db = Typecho_Db::get();
    $widget = new $className(Typecho_Request::getInstance(), Typecho_Widget_Helper_Empty::getInstance());
    
    $db->fetchRow(
        $widget->select()->where("{$key} = ?", $pkId)->limit(1),
            array($widget, 'push'));

    return $widget;
}

class VOID_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $body = null;
    public function action()
    {
        $this->body = json_decode(file_get_contents('php://input'), true);

        $this->on($this->request->is('content'))->vote_content();
        $this->on($this->request->is('comment'))->vote_comment();
        $this->on($this->request->is('show'))->vote_show();

        //$this->response->goBack();
    }

    private function vote_comment()
    {
        if($this->body['type'] == 'up') {
            $this->vote_excute('comments', 'coid', $this->body['id'], 'likes', 'up');
        } else {
            $this->vote_excute('comments', 'coid', $this->body['id'], 'dislikes', 'down');
        }
    }

    private function vote_content()
    {
        $this->vote_excute('contents', 'cid', $this->body['id'], 'likes', 'up');
    }

    private function vote_show ()
    {
        $db = Typecho_Db::get();
        $pageSize = 10;

        Typecho_Widget::widget('Widget_User')->to($user);
        if (!$user->have() || !$user->hasLogin()) {
            echo 'Invalid Request';
            exit;
        }

        header("Content-type:application/json");
        $older_than = null;
        if (array_key_exists('older_than', $_GET))
            $older_than = $_GET['older_than'];
        
        $query = $db->select()
                    ->from('table.votes')
                    ->order('table.votes.created', Typecho_Db::SORT_DESC)
                    ->limit($pageSize);
        if ($older_than)
            $query = $query->where('table.votes.created < ?', $older_than);
        
        $rows = $db->fetchAll($query);

        if (!count($rows)) {
            echo json_encode(array(
                'stamp' => -1,
                'data' => array()
            ));
            exit;
        }

        $arr = array(
            'stamp' => $rows[count($rows) - 1]['created'],
            'data' => array()
        );
        foreach ($rows as $row) {
            $instance = widgetById($row['table'], $row['id']);
            if (!$instance->have()) continue;

            $content = '';
            if ($row['table'] == 'comments') {
                $content = $instance->content;
                $content = Typecho_Common::stripTags($content);
                $content = mb_substr($content, 0, 12);
                $content .= '...';
            } else {
                $content = $instance->title;
            }

            $item = array(
                'vid' => $row['vid'],
                'url' => $instance->permalink,
                'from' => $row['table'],
                'content' => $content,
                'type' => $row['type'],
                'created' => $row['created'],
                'created_format' => date('Y-m-d H:i', $row['created']),
                'os' => ParseAgent::getOs($row['agent']),
                'browser' => ParseAgent::getBrowser($row['agent']),
                'location' => str_replace('中国', '', IPLocation_IP::locate($row['ip']))
            );
            $arr['data'][] = $item;
        }

        echo json_encode($arr);
    }

    private function vote_excute($table, $key, $id, $field, $type)
    {
        header("Content-type:application/json");
        $db = Typecho_Db::get();

        // 检测重复 IP
        $ip = $_SERVER['REMOTE_ADDR'];
        $rows = null;
        try {
            $rows = $db->fetchAll($db->select('type')
                        ->from('table.votes')
                        ->where('ip = ?', $ip)
                        ->where('id = ?', $id)
                        ->where('table = ?', $table));
        } catch (Typecho_Db_Query_Exception $th) {
            echo json_encode(array(
                'code'=> 500,
                'msg'=> $th->getMessage()
            ));
        }

        if(count($rows)) {
            $row = $rows[0];
            if ($row['type'] != $type) {
                // 不允许改变投票类型
                echo json_encode(array(
                    'code'=> 403,
                    'msg'=> 'can\'t change vote'
                ));
            } else {
                echo json_encode(array(
                    'code'=> 302,
                    'msg' => 'done'
                ));
            }
        } else {
            try {
                // 更新表
                $row = $db->fetchRow($db->select($field)
                            ->from('table.'.$table)
                            ->where($key.' = ?', $id));
                $newValue = (int)$row[$field] + 1;
                $db->query($db->update('table.'.$table)
                    ->rows(array($field => $newValue))
                    ->where($key.' = ?', $id));
            
                // 插入新投票记录
                $db->query($db->insert('table.votes')->rows(array(
                    'id' => $id,
                    'table' => $table,
                    'type' => $this->body['type'],
                    'agent' => $_SERVER['HTTP_USER_AGENT'],
                    'ip' => $ip,
                    'created' => time()
                )));

                echo json_encode(array(
                    'code'=> 200,
                    'msg'=> 'done'
                ));
            } catch (Typecho_Db_Query_Exception $th) {
                echo json_encode(array(
                    'code'=> 500,
                    'msg'=> $th->getMessage()
                ));
            }
        }
    }
}
