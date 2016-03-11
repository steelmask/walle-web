<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 一  9/21 13:48:30 2015
 *
 * @File Name: WalleController.php
 * @Description:
 * *****************************************************************/

namespace app\controllers;

use app\components\Repo;
use yii;
use yii\data\Pagination;
use app\components\Command;
use app\components\Folder;
use app\components\Git;
use app\components\Task as WalleTask;
use app\components\Ansible;
use app\components\Controller;
use app\models\Task;
use app\models\Record;
use app\models\Project;
use app\models\User;

class WalleController extends Controller {

    /**
     * 项目配置
     */
    protected $conf;

    /**
     * 上线任务配置
     */
    protected $task;

    /**
     * Walle的高级任务
     */
    protected $walleTask;

    /**
     * Ansible 任务
     */
    protected $ansible;

    /**
     * Walle的文件目录操作
     */
    protected $walleFolder;

    public $enableCsrfValidation = false;


    /**
     * 发起上线
     *
     * @throws \Exception
     */
    public function actionStartDeploy() {
        $taskId = \Yii::$app->request->post('taskId');
        if (!$taskId) {
            $this->renderJson([], -1, yii::t('walle', 'deployment id is empty'));
        }
        $this->task = Task::findOne($taskId);
        if (!$this->task) {
            throw new \Exception(yii::t('walle', 'deployment id not exists'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }
        // 任务失败或者审核通过时可发起上线
        if (!in_array($this->task->status, [Task::STATUS_PASS, Task::STATUS_FAILED])) {
            throw new \Exception(yii::t('walle', 'deployment only done for once'));
        }
        // 清除历史记录
        Record::deleteAll(['task_id' => $this->task->id]);

        // 项目配置
        $this->conf = Project::getConf($this->task->project_id);
        $this->walleTask   = new WalleTask($this->conf);
        $this->walleFolder = new Folder($this->conf);
        try {
            if ($this->task->action == Task::ACTION_ONLINE) {
                $this->_makeVersion();
                $this->_initWorkspace();
                $this->_preDeploy();
                $this->_gitUpdate();
                $this->_postDeploy();
                $this->_rsync();
                $this->_updateRemoteServers($this->task->link_id);
                $this->_cleanRemoteReleaseVersion();
                $this->_cleanUpLocal($this->task->link_id);
            } else {
                $this->_rollback($this->task->ex_link_id);
            }

            /** 至此已经发布版本到线上了，需要做一些记录工作 */

            // 记录此次上线的版本（软链号）和上线之前的版本
            ///对于回滚的任务不记录线上版本
            if ($this->task->action == Task::ACTION_ONLINE) {
                $this->task->ex_link_id = $this->conf->version;
            }
            // 第一次上线的任务不能回滚、回滚的任务不能再回滚
            if ($this->task->action == Task::ACTION_ROLLBACK || $this->task->id == 1) {
                $this->task->enable_rollback = Task::ROLLBACK_FALSE;
            }
            $this->task->status = Task::STATUS_DONE;
            $this->task->save();

            // 可回滚的版本设置
            $this->_enableRollBack();

            // 记录当前线上版本（软链）回滚则是回滚的版本，上线为新版本
            $this->conf->version = $this->task->link_id;
            $this->conf->save();
        } catch (\Exception $e) {
            $this->task->status = Task::STATUS_FAILED;
            $this->task->save();
            // 清理本地部署空间
            $this->_cleanUpLocal($this->task->link_id);

            throw $e;
        }
        $this->renderJson([]);
    }


    /**
     * 提交任务
     *
     * @return string
     */
    public function actionCheck() {
        $projects = Project::find()->asArray()->all();
        return $this->render('check', [
            'projects' => $projects,
        ]);
    }

    /**
     * 项目配置检测，提前发现配置不当之处。
     *
     * @return string
     */
    public function actionDetection($projectId) {
        $project = Project::getConf($projectId);
        $log = [];
        $code = 0;

        // 本地git ssh-key是否加入deploy-keys列表
        $revision = Repo::getRevision($project);
        try {
            $ret = $revision->updateRepo();
            if (!$ret) {
                $code  = -1;
                $error = $project->repo_type == Project::REPO_GIT
                    ? yii::t('walle', 'ssh-key to git')
                    : yii::t('walle', 'correct username passwd');
                $log[] = yii::t('walle', 'hosted server error', [
                    'user'       => getenv("USER"),
                    'path'       => $project->deploy_from,
                    'ssh_passwd' => $error,
                    'error'      => $revision->getExeLog(),
                ]);
            }
        } catch (\Exception $e) {
            $code = -1;
            $log[] = yii::t('walle', 'hosted server sys error', [
                'error' => $e->getMessage()
            ]);
        }

        try {
            if ($project->ansible) {
                $this->ansible = new Ansible($project);

                // 检测 ansible 是否安装
                $ret = $this->ansible->test();
                if (!$ret) {
                    $code = -1;
                    $log[] = yii::t('walle', 'hosted server ansible error');
                }

                // 检测 ansible 连接目标机是否正常
                $ret = $this->ansible->ping();
                if (!$ret) {
                    $code = -1;
                    $log[] = yii::t('walle', 'target server ansible ping error');
                }
            }
        } catch (\Exception $e) {
            $code = -1;
            $log[] = yii::t('walle', 'hosted server sys error', [
                'error' => $e->getMessage()
            ]);
        }

        // 权限与免密码登录检测
        $this->walleTask = new WalleTask($project);
        try {
            $command = sprintf('mkdir -p %s', Project::getReleaseVersionDir('detection'));
            $ret = $this->walleTask->runRemoteTaskCommandPackage([$command]);
            if (!$ret) {
                $code = -1;
                $log[] = yii::t('walle', 'target server error', [
                    'local_user'  => getenv("USER"),
                    'remote_user' => $project->release_user,
                    'path'        => $project->release_to,
                    'error'       => $this->walleTask->getExeLog(),
                ]);
            }
            // 清除
            $command = sprintf('rm -rf %s', Project::getReleaseVersionDir('detection'));
            $this->walleTask->runRemoteTaskCommandPackage([$command]);
        } catch (\Exception $e) {
            $code = -1;
            $log[] = yii::t('walle', 'target server sys error', [
                'error' => $e->getMessage()
            ]);
        }

        // task 检测todo...

        if ($code === 0) {
            $log[] = yii::t('walle', 'project configuration works');
        }
        $this->renderJson(join("<br>", $log), $code);
    }

    /**
     * 获取线上文件md5
     *
     * @param $projectId
     */
    public function actionFileMd5($projectId, $file) {
        // 配置
        $this->conf = Project::getConf($projectId);

        $this->walleFolder = new Folder($this->conf);
        $projectDir = $this->conf->release_to;
        $file = sprintf("%s/%s", rtrim($projectDir, '/'), $file);

        $this->walleFolder->getFileMd5($file);
        $log = $this->walleFolder->getExeLog();

        $this->renderJson(nl2br($log));
    }

    /**
     * 获取branch分支列表
     *
     * @param $projectId
     */
    public function actionGetBranch($projectId) {
        $conf = Project::getConf($projectId);

        $version = Repo::getRevision($conf);
        $list = $version->getBranchList();

        $this->renderJson($list);
    }

    /**
     * 获取commit历史
     *
     * @param $projectId
     */
    public function actionGetCommitHistory($projectId, $branch = 'master') {
        $conf = Project::getConf($projectId);
        $revision = Repo::getRevision($conf);
        if ($conf->repo_mode == Project::REPO_TAG && $conf->repo_type == Project::REPO_GIT) {
            $list = $revision->getTagList();
        } else {
            $list = $revision->getCommitList($branch);
        }
        $this->renderJson($list);
    }

    /**
     * 获取commit之间的文件
     *
     * @param $projectId
     */
    public function actionGetCommitFile($projectId, $start, $end, $branch = 'trunk') {
        $conf = Project::getConf($projectId);
        $revision = Repo::getRevision($conf);
        $list = $revision->getFileBetweenCommits($branch, $start, $end);

        $this->renderJson($list);
    }

    /**
     * 上线管理
     *
     * @param $taskId
     * @return string
     * @throws \Exception
     */
    public function actionDeploy($taskId) {
        $this->task = Task::find()
            ->where(['id' => $taskId])
            ->with(['project'])
            ->one();
        if (!$this->task) {
            throw new \Exception(yii::t('walle', 'deployment id not exists'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }

        return $this->render('deploy', [
            'task' => $this->task,
        ]);
    }

    /**
     * 获取上线进度
     *
     * @param $taskId
     */
    public function actionGetProcess($taskId) {
        $record = Record::find()
            ->select(['percent' => 'action', 'status', 'memo', 'command'])
            ->where(['task_id' => $taskId,])
            ->orderBy('id desc')
            ->asArray()->one();
        $record['memo'] = stripslashes($record['memo']);
        $record['command'] = stripslashes($record['command']);

        $this->renderJson($record);
    }

    /**
     * 产生一个上线版本
     */
    private function _makeVersion() {
        $version = date("Ymd-His", time());
        $this->task->link_id = $version;
        return $this->task->save();
    }

    /**
     * 检查目录和权限，工作空间的准备
     * 每一个版本都单独开辟一个工作空间，防止代码污染
     *
     * @return bool
     * @throws \Exception
     */
    private function _initWorkspace() {
        $sTime = Command::getMs();
        // 本地宿主机工作区初始化
        $this->walleFolder->initLocalWorkspace($this->task->link_id);

        // 远程目标目录检查，并且生成版本目录
        $ret = $this->walleFolder->initRemoteVersion($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleFolder, $this->task->id, Record::ACTION_PERMSSION, $duration);

        if (!$ret) throw new \Exception(yii::t('walle', 'init deployment workspace error'));
        return true;
    }

    /**
     * 更新代码文件
     *
     * @return bool
     * @throws \Exception
     */
    private function _gitUpdate() {
        // 更新代码文件
        $revision = Repo::getRevision($this->conf);
        $sTime = Command::getMs();
        $ret = $revision->updateToVersion($this->task); // 更新到指定版本
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($revision, $this->task->id, Record::ACTION_CLONE, $duration);

        if (!$ret) throw new \Exception(yii::t('walle', 'update code error'));
        return true;
    }

    /**
     * 部署前置触发任务
     * 在部署代码之前的准备工作，如git的一些前置检查、vendor的安装（更新）
     *
     * @return bool
     * @throws \Exception
     */
    private function _preDeploy() {
        $sTime = Command::getMs();
        $ret = $this->walleTask->preDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::ACTION_PRE_DEPLOY, $duration);

        if (!$ret) throw new \Exception(yii::t('walle', 'pre deploy task error'));
        return true;
    }


    /**
     * 部署后置触发任务
     * git代码检出之后，可能做一些调整处理，如vendor拷贝，配置环境适配（mv config-test.php config.php）
     *
     * @return bool
     * @throws \Exception
     */
    private function _postDeploy() {
        $sTime = Command::getMs();
        $ret = $this->walleTask->postDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::ACTION_POST_DEPLOY, $duration);

        if (!$ret) throw new \Exception(yii::t('walle', 'post deploy task error'));
        return true;
    }

    /**
     * 同步文件到服务器
     *
     * @return bool
     * @throws \Exception
     */
    private function _rsync() {
        // 同步文件
        foreach (Project::getHosts() as $remoteHost) {
            $sTime = Command::getMs();
            $ret = $this->walleFolder->syncFiles($remoteHost, $this->task->link_id);
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($this->walleFolder, $this->task->id, Record::ACTION_SYNC, $duration);
            if (!$ret) throw new \Exception(yii::t('walle', 'rsync error'));
        }
        return true;
    }

    /**
     * 执行远程服务器任务集合
     * 对于目标机器更多的时候是一台机器完成一组命令，而不是每条命令逐台机器执行
     *
     * @param $version
     * @throws \Exception
     */
    private function _updateRemoteServers($version) {
        $cmd = [];
        // pre-release task
        if (($preRelease = WalleTask::getRemoteTaskCommand($this->conf->pre_release, $version))) {
            $cmd[] = $preRelease;
        }
        // link
        if (($linkCmd = $this->walleFolder->getLinkCommand($version))) {
            $cmd[] = $linkCmd;
        }
        // post-release task
        if (($postRelease = WalleTask::getRemoteTaskCommand($this->conf->post_release, $version))) {
            $cmd[] = $postRelease;
        }

        $sTime = Command::getMs();
        // run the task package
        $ret = $this->walleTask->runRemoteTaskCommandPackage($cmd);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($this->walleTask, $this->task->id, Record::ACTION_UPDATE_REMOTE, $duration);
        if (!$ret) throw new \Exception(yii::t('walle', 'update servers error'));
        return true;
    }

    /**
     * 可回滚的版本设置
     *
     * @return int
     */
    private function _enableRollBack() {
        $where = ' status = :status AND project_id = :project_id ';
        $param = [':status' => Task::STATUS_DONE, ':project_id' => $this->task->project_id];
        $offset = Task::find()
            ->select(['id'])
            ->where($where, $param)
            ->orderBy(['id' => SORT_DESC])
            ->offset($this->conf->keep_version_num)->limit(1)
            ->scalar();
        if (!$offset) return true;

        $where .= ' AND id <= :offset ';
        $param[':offset'] = $offset;
        return Task::updateAll(['enable_rollback' => Task::ROLLBACK_FALSE], $where, $param);
    }

    /**
     * 只保留最大版本数，其余删除过老版本
     */
    private function _cleanRemoteReleaseVersion() {
        return $this->walleTask->cleanUpReleasesVersion();
    }

    /**
     * 执行远程服务器任务集合回滚，只操作pre-release、link、post-release任务
     *
     * @param $version
     * @throws \Exception
     */
    public function _rollback($version) {
        return $this->_updateRemoteServers($version);
    }

    /**
     * 收尾工作，清除宿主机的临时部署空间
     */
    private function _cleanUpLocal($version = null) {
        // 创建链接指向
        $this->walleFolder->cleanUpLocal($version);
        return true;
    }

}
