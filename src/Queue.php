<?php
/**
 * User: Chester-He
 */

namespace Chester\BackgroundMission;


use Chester\BackgroundMission\DataBases\BackgroundTasks;
use Chester\BackgroundMission\Exceptions\MethodIsRunningException;
use Chester\BackgroundMission\Exceptions\TaskMethodNotFoundException;
use Chester\BackgroundMission\Exceptions\TimeOutException;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CacheMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Mutex;
use Illuminate\Container\Container;

class Queue
{
    protected $manager;
    protected $executor;

    /**
     * 执行频率设置
     * @var int
     */
    protected $frequency = 1;

    /**
     * Commands 命令名称
     * @var string
     */
    protected $command = 'mission:execute';
    /**
     * @var string out put file for command.
     */
    protected $sendOutputTo;

    protected $withoutRepeatMethod = false;

    public function __construct(MissionInterface $manager, Logic $executor)
    {
        $this->manager = $manager;
        $this->executor = $executor;
    }

    public function withoutRepeatMethod()
    {
        $this->withoutRepeatMethod = true;
        return $this;
    }

    /**
     * 获取任务记录
     * @param string $type
     * @param int $limit
     * @return mixed
     */
    public function records($type = 'system', $limit = 10)
    {
        return $this->manager->getLastTasksByType($type, $limit);
    }

    /**
     * @param $params
     * @throws MethodIsRunningException
     */
    public function push($params)
    {
        if ($this->withoutRepeatMethod && $this->manager->existTask($params['method'])) {
            throw new MethodIsRunningException();
        }
        $this->manager->addTask($params);
        $this->runTask();
    }

    /**
     * 执行任务，需要手动调用，否则无法触发命令
     */
    protected function runTask()
    {
        $container = app();
        $mutex = $container->bound(Mutex::class)
            ? $container->make(Mutex::class)
            : $container->make(CacheMutex::class);
        $event = new Event($mutex, Application::formatCommandString($this->command));
        $event->runInBackground();
        $event->sendOutputTo($this->getOutputTo());
        $event->run(app());
    }

    protected function getOutputTo()
    {
        if (!$this->sendOutputTo) {
            $this->sendOutputTo = storage_path('app/background-task.output');
        }
        return $this->sendOutputTo;
    }

    public function frequencyRun()
    {
        if (!$this->manager->hasInitTask()) {
            return;
        }
        $taskList = $this->manager->getLastInitTasks($this->frequency);
        $task_ids = collect($taskList)->pluck('unique_id');
        $this->manager->changeTaskStateByIds($task_ids, BackgroundTasks::STATE_EXECUTING);

        foreach ($taskList as $task) {
            $method = $task['method'];
            $params = $this->jsonDecode($task['params']);
            try {
                if (method_exists($this->executor, $method)) {
                    $result = $this->executor->$method($params);
                } else {
                    throw new TaskMethodNotFoundException();
                }
            } catch (\Exception $exception) {
                $this->manager->changeTaskStateByIds($task['unique_id'], BackgroundTasks::STATE_FAIL, $exception->getMessage());
                continue;
            }
            $state = $result['state'] ? BackgroundTasks::STATE_SUCCESS : BackgroundTasks::STATE_FAIL;
            $this->manager->changeTaskStateByIds($task['unique_id'], $state, $result['content']);
        }
    }

    protected function jsonDecode($string)
    {
        return json_decode($string, true);
    }
}