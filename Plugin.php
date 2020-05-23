<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * VOID 二次开发主题配套插件 <strong style="color:red;">禁止与原版同时开启</strong>
 * 
 * @package VOID
 * @author Monst.x
 * @version 1.21
 * @link https://monsterx.cn
 */

require_once('libs/WordCount.php');
require_once('libs/IP.php');
require_once('libs/ParseImg.php');
require_once('libs/PandaBangumi.php');

class VOID_Plugin implements Typecho_Plugin_Interface
{
    public static $VERSION = 1.21;

    private static function hasColumn($table, $field) {
        $db = Typecho_Db::get();
        $sql = "SHOW COLUMNS FROM `".$table."` LIKE '%".$field."%'";
        return count($db->fetchAll($sql)) != 0;
    }

    private static function hasTable($table) {
        $db = Typecho_Db::get();
        $sql = "SHOW TABLES LIKE '%".$table."%'";
        return count($db->fetchAll($sql)) != 0;
    }

    private static function queryAndCatch($sql) {
        $db = Typecho_Db::get();
        try {
            $db->query($sql);
        } catch (Typecho_Db_Query_Exception $th) {
            throw new Typecho_Plugin_Exception($th->getMessage());
        }
    }

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 检查数据库类型
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName =  strtolower($db->getAdapterName());
        if (strpos($adapterName, 'mysql') < 0) {
            throw new Typecho_Plugin_Exception('启用失败，本插件暂时只支持 MySQL 数据库，您的数据库是：'.$adapterName);
        }

        // 检查是否存在对应扩展
        if (!extension_loaded('openssl')) {
            throw new Typecho_Plugin_Exception('启用失败，PHP 需启用 OpenSSL 扩展。');
        }
        if (!extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception('启用失败，PHP 需启用 CURL 扩展。');
        }

        /** 图片附件尺寸解析，注册 hook */
        Typecho_Plugin::factory('Widget_Upload')->upload = array('VOID_Plugin', 'upload');

        /** Gravatar 头像解析替换 */
        Typecho_Plugin::factory('Widget_Abstract_Comments')->gravatar = array('VOID_Plugin', 'render');
        
        /** 字数统计 */
        // contents 表中若无 wordCount 字段则添加
        if (!self::hasColumn($prefix.'contents', 'wordCount')) {
            self::queryAndCatch('ALTER TABLE `'. $prefix .'contents` ADD COLUMN `wordCount` INT(10) DEFAULT 0;');
        }
        // 更新一次字数统计
        VOID_WordCount::updateAllWordCount();
        // 注册 hook
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('VOID_Plugin', 'updateContent');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('VOID_Plugin', 'updateContent');
        // 加入查询
        Typecho_Plugin::factory('Widget_Archive')->___wordCount = array('VOID_Plugin', 'wordCount');

        /** 文章点赞 */
        // 创建字段
        if (!self::hasColumn($prefix.'contents', 'likes')) {
            self::queryAndCatch('ALTER TABLE `'. $prefix .'contents` ADD COLUMN `likes` INT(10) DEFAULT 0;');
        }
        // 加入查询
        Typecho_Plugin::factory('Widget_Archive')->___likes = array('VOID_Plugin', 'likes');
        
        /** 评论赞踩 */
        // 创建字段
        if (!self::hasColumn($prefix.'comments', 'likes')) {
            self::queryAndCatch('ALTER TABLE `'. $prefix .'comments` ADD COLUMN `likes` INT(10) DEFAULT 0;');
        }
        if (!self::hasColumn($prefix.'comments', 'dislikes')) {
            self::queryAndCatch('ALTER TABLE `'. $prefix .'comments` ADD COLUMN `dislikes` INT(10) DEFAULT 0;');
        }

        /** 浏览量统计 */
        // 创建字段
        if (!self::hasColumn($prefix.'contents', 'viewsNum')) {
            self::queryAndCatch('ALTER TABLE `'. $prefix .'contents` ADD COLUMN `viewsNum` INT(10) DEFAULT 0;');
        }
        //增加浏览数
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('VOID_Plugin', 'updateViewCount');
        // 加入查询
        Typecho_Plugin::factory('Widget_Archive')->___viewsNum = array('VOID_Plugin', 'viewsNum');

        /** 点赞与投票数据库 */
        // 创建表，保存点赞与投票相关信息
        $table_name = $prefix . 'votes';
        if (!self::hasTable($table_name)) {
            $sql = 'create table IF NOT EXISTS `'.$table_name.'` (
                `vid` int unsigned auto_increment,
                `id` int unsigned not null,
                `table` char(32) not null,
                `type` char(32) not null,
                `agent` text,
                `ip` varchar(128),
                `created` int unsigned default 0,
                primary key (`vid`)
            ) default charset=utf8;
            CREATE INDEX index_ip ON '.$table_name.'(`ip`);
            CREATE INDEX index_id ON '.$table_name.'(`id`);
            CREATE INDEX index_table ON '.$table_name.'(`table`)';

            $sqls = explode(';', $sql);
            foreach ($sqls as $sql) {
                self::queryAndCatch($sql);
            }
        } else {
            if (!self::hasColumn($prefix.'votes', 'created')) {
                self::queryAndCatch('ALTER TABLE `'. $prefix .'votes` ADD COLUMN `created` INT(10) DEFAULT 0;');
            }
        }

        // 创建 Exsearch 数据表
        $exsearchtb_name = $prefix . 'exsearch';
        $sql = "SHOW TABLES LIKE '%" . $exsearchtb_name . "%'";
        if (count($db->fetchAll($sql)) == 0) {
            $sql = '
            DROP TABLE IF EXISTS `'.$exsearchtb_name.'`;
            create table `'.$exsearchtb_name.'` (
                `id` int unsigned auto_increment,
                `key` char(32) not null,
                `data` longtext,
                primary key (`id`)
            ) default charset=utf8';

            $sqls = explode(';', $sql);
            foreach ($sqls as $sql) {
                $db->query($sql);
            }
        } else {
            $db->query($db->delete('table.exsearch')->where('id >= ?', 0));
        }

        // 添加一个面板，展示互动信息，例如评论赞踩、文章点赞
        Helper::addPanel(3, 'VOID/pages/showActivity.php', '互动', '查看访客互动', 'administrator');

        // 添加投票路由，文章与评论
        Helper::addAction('void', 'VOID_Action');

        // 评论列表显示来源
        Typecho_Plugin::factory('Widget_Comments_Admin')->callIp = array('VOID_Plugin', 'commentLocation');

        // 添加 PandaBangumi 路由
        Helper::addRoute("route_PandaBangumi","/PandaBangumi","VOID_Action",'action');

        // 添加 ExSearch 路由
        Helper::addRoute("route_ExSearch","/ExSearch","VOID_Action",'action');

        // 注册 Exsearch 文章、页面保存时的 hook（JSON 写入数据库）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('VOID_Plugin', 'save');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('VOID_Plugin', 'save');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
	{
        Helper::removeAction('void');
        Helper::removeAction('void_vote');
        Helper::removePanel(3, 'VOID/pages/showActivity.php');

        // PandaBangumi 禁用方法
        Helper::removeRoute("route_PandaBangumi");

        // Exsearch 禁用方法
        // 删除路由
        Helper::removeRoute("route_ExSearch");
        // Drop Exsearch 表
        $db= Typecho_Db::get();
        $exsearchtb_name = $db->getPrefix() . 'exsearch';
        $sql = 'DROP TABLE IF EXISTS `'.$exsearchtb_name.'`';
        $db->query($sql);
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<style>p.notice {line-height: 1.75; padding: .5rem; padding-left: .75rem; border-left: solid 4px #fbbc05; background: rgba(0,0,25,.025);}</style>';
        echo '<p class="notice">作者：<a href="https://www.imalan.cn" target="_blank">熊猫小A</a>，由 <a href="https://monsterx.cn" target="_blank">Monst.x</a> 融合功能<br>';
        echo '功能包含：<a href="https://github.com/AlanDecode/VOID-Plugin" target="_blank">VOID</a> & <a href="https://github.com/kraity/typecho-gravatar" target="_blank">GravatarServer</a> & <a href="https://github.com/AlanDecode/Typecho-Plugin-ExSearch" target="_blank">ExSearch</a> & <a href="https://github.com/AlanDecode/Typecho-Plugin-PandaBangumi" target="_blank">PandaBangumi</a><br>';
        echo '<strong>GravatarServer 使用方法</strong> 在下方选择头像服务器、优先原则和默认头像，然后保存设置<br>';
        echo '<strong>ExSearch 使用方法</strong> 打开下方开关后保存设置，然后 <a href="' .Helper::options()->index. '/ExSearch?action=rebuild" target="_blank">重建索引</a> （重建索引会清除所有缓存数据）<br>';
        echo '<strong>PandaBangumi 使用方法</strong> 新建独立页面选中 Bgm 追番模板，如需修改模板请参考该插件说明</p><br><br>';

        /** Gravatar 面板 */
        // 头像服务器
        $server = new Typecho_Widget_Helper_Form_Element_Radio('server', array(
            'https://gravatar.loli.net/avatar' => 'loli 镜像 (https://gravatar.loli.net)',
            'https://gravatar.cat.net/avatar' => 'cat 镜像 (https://gravatar.cat.net)',
            'https://cdn.v2ex.com/gravatar' => 'v2ex 镜像 (https://cdn.v2ex.com)',
            'https://dn-qiniu-avatar.qbox.me/avatar/' => 'qiniu 镜像 (https://dn-qiniu-avatar.qbox.me)',
            'https://sdn.geekzu.org/avatar/' => 'geekzu 镜像 (https://sdn.geekzu.org)',
            'https://secure.gravatar.com/avatar' => 'Gravatar Secure (https://secure.gravatar.com)'),
            'https://gravatar.loli.net/avatar', _t('Gravatar 服务器'), _t('替换 Typecho 默认的 Gravatar 服务器 (www.gravatar.com)。'));
        $form->addInput($server->multiMode());
        // 头像优先原则
        $usePriority = new Typecho_Widget_Helper_Form_Element_Radio('usePriority',
            array(
                'gr' => _t('优先 Gravatar 头像'),
                'qq' => _t('优先 QQ 头像'),
            ),
            'gr', _t('头像优先原则'), _t('默认优先使用 Gravatar 头像。若优先使用 QQ 头像：仅邮箱为腾讯 QQ 邮箱且用户名为合法 QQ 号码时使用加密地址 QQ 头像，其他依旧使用 Gravatar 头像。<br><br>'));
        $form->addInput($usePriority);
        // 默认头像
        $defaultimg = new Typecho_Widget_Helper_Form_Element_Radio('defaultimg', array(
            'blank' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=blank height="32" width="32"  alt=""/> Blank',
            '' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d= height="32" width="32"  alt=""/> 默认',
            'mm' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=mm height="32" width="32" alt=""/> 轮廓',
            'identicon' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=identicon height="32" width="32"  alt=""/> 几何（生成）',
            'wavatar' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=wavatar height="32" width="32"  alt=""/> 面孔（生成）',
            'retro' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=retro height="32" width="32"  alt=""/> 街机（生成）',
            'monsterid' => '<img src=https://gravatar.loli.net/avatar/926f6ea036f9236ae1ceec566c2760ea?s=32&r=G&forcedefault=1&d=monsterid height="32" width="32"  alt=""/> 怪兽（生成）'),
            'mm', _t('默认头像'), _t('当评论者没有设置 Gravatar 头像时默认显示该头像。'));
        $form->addInput($defaultimg->multiMode());
        
        /** ExSearch 面板 */
        // ExSearch 开关
        $exswitch = new Typecho_Widget_Helper_Form_Element_Radio(
            'exswitch',
            array('true' => '是','false' => '否'),
            'true',
            '开启 Exsearch',
            '开启 ExSearch 可优化主题搜索功能，实现即时搜索。'
        );
        $form->addInput($exswitch);
        // JSON 静态化
        $exstatic = new Typecho_Widget_Helper_Form_Element_Radio(
            'static',
            array('true' => '是','false' => '否'),
            'true',
            '静态化',
            '静态化可以节省数据库调用，降低服务器压力。<mark>若需启用，需要保证本插件目录中 cache 文件夹可写。</mark>'
        );
        $form->addInput($exstatic);
        // Json 文件地址
        $exjson = new Typecho_Widget_Helper_Form_Element_Text('exjson', NULL, '', _t('ExSearch Json 地址'), _t('如果不明白这是什么，请务必保持此项为空！<br><br>'));
        $form->addInput($exjson);


        /** PandaBangumi 面板 */
        // PandaBangumi 开关
        $bgmswitch = new Typecho_Widget_Helper_Form_Element_Radio(
            'bgmswitch',
            array('true' => '是','false' => '否'),
            'true',
            '开启 PandaBangumi',
            '开启 PandaBangumi 可扩展主题独立模板，实现追番展示。'
        );
        $form->addInput($bgmswitch);
        $ID = new Typecho_Widget_Helper_Form_Element_Text('ID', NULL, '', _t('用户 ID'), 
            _t('填写你的 Bangumi 主页链接 user 后面那一串数字。'));
        $form->addInput($ID);
        $PageSize = new Typecho_Widget_Helper_Form_Element_Text('PageSize', NULL, '12', _t('每页数量'), 
            _t('填写番剧列表每页数量，填写 -1 则在一页内全部显示，默认为 12。'));
        $form->addInput($PageSize);
        $ValidTimeSpan = new Typecho_Widget_Helper_Form_Element_Text('ValidTimeSpan', NULL, '86400', _t('缓存过期时间'), 
            _t('设置缓存过期时间，单位为秒，默认 24 小时。'));
        $form->addInput($ValidTimeSpan);
        $ParseMethod = new Typecho_Widget_Helper_Form_Element_Radio('ParseMethod', array(
            'api' => 'API',
            'webpage' => '网页'), 'api', 
            '已看列表解析方式', 'API 解析相对稳定，但是有最多获取最近 25 部的限制。网页解析速度可能较慢，但能获取更多记录。不影响在看列表。');
        $form->addInput($ParseMethod);
        $Limit = new Typecho_Widget_Helper_Form_Element_Text('Limit', NULL, '20', _t('已看列表数量限制'), _t('设置获取数量限制，不建议设置得太大，有被 Bangumi 拉黑的风险。<b>仅当通过网页解析时有效</b>。不影响在看列表。<br><br>'));
        $form->addInput($Limit);

        

        /** VOID 默认面板 */
        // 可设置每次获取图片基础信息数量上限
        $parseImgLimit = new Typecho_Widget_Helper_Form_Element_Text('parseImgLimit', NULL, '10', _t('单次图片处理数量上限'), 
            _t('这里是每次获取图片基础信息的数量上限。不建议设置过大的数值，太大可能导致处理超时。'));
        $form->addInput($parseImgLimit);
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 返回文章字数
     */
    public static function viewsNum($archive)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('viewsNum')
            ->from('table.contents')
            ->where('cid = ?', $archive->cid));
        return $row['viewsNum'];
    }

    /**
     * 返回文章点赞数
     */
    public static function likes($archive)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('likes')
            ->from('table.contents')
            ->where('cid = ?', $archive->cid));
        return $row['likes'];
    }

    /**
     * 返回文章字数
     */
    public static function wordCount($archive)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('wordCount')
            ->from('table.contents')
            ->where('cid = ?', $archive->cid));
        return $row['wordCount'];
    }

    /**
     * 更新文章字数统计
     * 
     * @access public
     * @param  mixed $archive
     * @return void
     */
    public static function updateContent($contents, $widget)
    {
        VOID_WordCount::wordCountByCid($widget->cid);
        $ret = VOID_ParseImgInfo::parse($widget->cid);
    }

    /**
     * 更新文章浏览量
     * 
     * @param Widget_Archive   $archive
     * @return void
     */
    public static function updateViewCount($archive)
    {
        if($archive->is('single')){
            $cid = $archive->cid;
            $views = Typecho_Cookie::get('__void_post_views');
            if(empty($views)){
                $views = array();
            } else {
                $views = explode(',', $views);
            }
            if(!in_array($cid,$views)){
                $db = Typecho_Db::get();
                $row = $db->fetchRow($db->select('viewsNum')
                    ->from('table.contents')
                    ->where('cid = ?', $cid));
                $db->query($db->update('table.contents')
                    ->rows(array('viewsNum' => (int)$row['viewsNum']+1))
                    ->where('cid = ?', $cid));
                array_push($views, $cid);
                $views = implode(',', $views);
                Typecho_Cookie::set('__void_post_views', $views); //记录查看cookie
            }
        }
    }

    /**
     * 在附件链接尾部添加后缀
     * 
     * @access public
     * @param  Widget_Upload $uploadObj 上传对象
     * @return void
     */
    public static function upload($uploadObj)
    {
        // 若是图片，则增加后缀
        if ($uploadObj->attachment->isImage) {
            $meta = getimagesize(__TYPECHO_ROOT_DIR__.$uploadObj->attachment->path);
            if ($meta != false) {
                $uploadObj->attachment->url = 
                    $uploadObj->attachment->url.'#vwid='.$meta[0].'&vhei='.$meta[1];
            }
        }
    }

    /**
     * 插件实现方法
     * 
     * @access public
     * @param Typecho_Widget $comments 评论
     * @return void
     */
    public static function commentLocation($comments)
    {
        $location = IPLocation_IP::locate($comments->ip);
        echo $comments->ip . '<br>' . $location;
    }

    /**
     * 根据 cid 生成对象
     * 
     * @access private
     * @param string $table 表名, 支持 contents, comments, metas, users
     * @return Widget_Abstract
     */
    private static function widget($table, $pkId)
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

    /** 以下 ExSearch 方法 */
    /**
     * 更新数据库
     * 
     * @access public
     * @return void
     */
    public static function save()
    {
        $db = Typecho_Db::get();

        // 防止过大的内容导致 MySQL 报错，需要高级权限
        // $sql = 'SET GLOBAL max_allowed_packet=4294967295;';
        // $db->query($sql);

        // 删除原本的记录
        self::clean();

        // 获取搜索范围配置，query 对应内容
        $cache = array();
        $cache['posts'] = self::build('post');
        $cache['pages'] = self::build('page');

        $cache = json_encode($cache);
        $md5 = md5($cache);

        if(Helper::options()->plugin('VOID')->static == 'true')
        {
            $code = file_put_contents(__DIR__.'/cache/exsearch-'.$md5.'.json', $cache);
            if($code < 1)
            {
                throw new Typecho_Plugin_Exception('ExSearch 索引写入失败，请保证缓存目录可写', 1);
                exit(1);
            }
            $db->query($db->insert('table.exsearch')->rows(array(
                'key' => $md5,
                'data' => ''
            )));
        }
        else
        {
            $db->query($db->insert('table.exsearch')->rows(array(
                'key' => $md5,
                'data' => $cache
            )));
        }
    }

    /**
     * 删除缓存（数据库与静态缓存）
     * 
     * @access private
     * @return bool
     */
    private static function clean()
    {
        $db = Typecho_Db::get();
        $exsearchtb_name = $db->getPrefix() . 'exsearch';
        $sql = "SHOW TABLES LIKE '%" . $exsearchtb_name . "%'";
        if(count($db->fetchAll($sql)) != 0){
            $db->query($db->delete('table.exsearch')->where('id >= ?', 0));
        }

        // 删除静态缓存
        foreach (glob(__DIR__.'/cache/exsearch*.json') as $file) {
            unlink($file);
        }
    }

    /**
     * 生成对象
     * 
     * @access private
     * @return array
     */
    private static function build($type)
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select()->from('table.contents')
                ->where('table.contents.type = ?', $type)
                ->where('table.contents.status = ?', 'publish')
                ->where('table.contents.password IS NULL')
                ->order('table.contents.created', Typecho_Db::SORT_DESC));
        $cache = array();
        foreach ($rows as $row) {
            $widget = self::widget('Contents', $row['cid']);
            $item = array(
                'title' => $row['title'],
                'date' => date('c', $row['created']),
                'path' => $widget->permalink,
                'text' => strip_tags($widget->content)
            );

            if($type == 'post')
            {
                // 分类与标签
                $tags = array();
                $cates = array();
                $mids = $db->fetchAll($db->select()->from('table.relationships')
                        ->where('table.relationships.cid = ?', $row['cid']));

                foreach ($mids as $mid) {
                    $mid = $mid['mid'];
                    $meta = self::widget('Metas', $mid);
                    if($meta->type == 'category')
                    {
                        $cates[] = array(
                            'name' => $meta->name,
                            'slug' => $meta->slug,
                            'permalink' => $meta->permalink
                        );
                    }
                    if($meta->type == 'tag')
                    {
                        $tags[] = array(
                            'name' => $meta->name,
                            'slug' => $meta->slug,
                            'permalink' => $meta->permalink
                        );
                    }
                }
                $item['tags'] = $tags;
                $item['categories'] = $cates;
            }

            $cache[]=$item;
        }
        return $cache;
    }

    /** GravatarServer 方法 */
    /**
     * 插件实现方法
     *
     * @access public
     * @return void
     * @throws Typecho_Exception
     */
    public static function render($size, $rating, $default, $comments)
    {
        $defaultimg = Typecho_Widget::widget('Widget_Options')->plugin('VOID')->defaultimg;
        $url = self::gravatarUrl($comments->mail, $size, $rating, $defaultimg, $comments->request->isSecure());
        echo '<img class="avatar" src="' . $url . '" alt="' . $comments->author . '" width="' . $size . '" height="' . $size . '" />';
    }

    /**
     * 获取 Gravatar 头像地址
     *
     * @param string $mail
     * @param int $size
     * @param string $rating
     * @param string $default
     * @param bool $isSecure
     * @return string
     * @throws Typecho_Exception
     */
    public static function gravatarUrl($mail, $size, $rating, $default, $isSecure = false)
    {
        $hander = Typecho_Widget::widget('Widget_Options')->plugin('VOID');
        $secure = $isSecure ? 'https://secure.gravatar.com/avatar/' : $hander->server . "/";
        $s = "?s=" . $size;
        $r = "&r=" . $rating;
        $d = "&d=" . $default;
        if (empty($mail)) {
            return $secure . $s . $r . $d;
        } else {
            $reg = "/^\d{5,11}@[qQ][Qq]\.(com)$/";
            if (preg_match($reg, $mail) && $hander->usePriority == "qq") {
                $object = explode("@", $mail)[0];
                $url = "https://ptlogin2.qq.com/getface?appid=1006102&uin=" . $object . "&imgtype=3";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $avatar = curl_exec($ch);
                curl_close($ch);
                $pattern2 = '/pt.setHeader\((.*)\)/is';
                preg_match($pattern2, $avatar, $result2);
                return json_decode($result2[1], true)["$object"];
            } else {
                return $secure . md5($mail) . $s . $r . $d;
            }
        }
    }
}