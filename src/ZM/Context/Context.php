<?php


namespace ZM\Context;


use Co;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use swoole_server;
use ZM\ConnectionManager\ConnectionObject;
use ZM\ConnectionManager\ManagerGM;
use ZM\Exception\InvalidArgumentException;
use ZM\Exception\WaitTimeoutException;
use ZM\Http\Response;
use ZM\API\ZMRobot;
use ZM\Store\ZMBuf;

class Context implements ContextInterface
{
    public static $context = [];
    private $cid;

    public function __construct($cid) { $this->cid = $cid; }

    /**
     * @return swoole_server|null
     */
    public function getServer() { return self::$context[$this->cid]["server"] ?? null; }

    /**
     * @return Frame|null
     */
    public function getFrame() { return self::$context[$this->cid]["frame"] ?? null; }

    public function getFd() { return self::$context[$this->cid]["fd"] ?? $this->getFrame()->fd ?? null; }

    /**
     * @return array|null
     */
    public function getData() { return self::$context[$this->cid]["data"] ?? null; }

    public function setData($data) { self::$context[$this->cid]["data"] = $data; }

    /**
     * @return Request|null
     */
    public function getRequest() { return self::$context[$this->cid]["request"] ?? null; }

    /**
     * @return Response|null
     */
    public function getResponse() { return self::$context[$this->cid]["response"] ?? null; }

    /** @return ConnectionObject|null */
    public function getConnection() { return ManagerGM::get($this->getFd()); }

    /**
     * @return int|null
     */
    public function getCid() { return $this->cid; }

    /**
     * @return ZMRobot|null
     */
    public function getRobot() {
        $conn = ManagerGM::get($this->getFrame()->fd);
        return $conn instanceof ConnectionObject ? new ZMRobot($conn) : null;
    }

    public function getMessage() { return self::$context[$this->cid]["data"]["message"] ?? null; }

    public function setMessage($msg) { self::$context[$this->cid]["data"]["message"] = $msg; }

    public function getUserId() { return $this->getData()["user_id"] ?? null; }

    public function setUserId($id) { self::$context[$this->cid]["data"]["user_id"] = $id; }

    public function getGroupId() { return $this->getData()["group_id"] ?? null; }

    public function setGroupId($id) { self::$context[$this->cid]["data"]["group_id"] = $id; }

    public function getDiscussId() { return $this->getData()["discuss_id"] ?? null; }

    public function setDiscussId($id) { self::$context[$this->cid]["data"]["discuss_id"] = $id; }

    public function getMessageType() { return $this->getData()["message_type"] ?? null; }

    public function setMessageType($type) { self::$context[$this->cid]["data"]["message_type"] = $type; }

    public function getRobotId() { return $this->getData()["self_id"] ?? null; }

    public function getCache($key) { return self::$context[$this->cid]["cache"][$key] ?? null; }

    public function setCache($key, $value) { self::$context[$this->cid]["cache"][$key] = $value; }

    public function getCQResponse() { return self::$context[$this->cid]["cq_response"] ?? null; }

    /**
     * only can used by cq->message event function
     * @param $msg
     * @param bool $yield
     * @return mixed
     */
    public function reply($msg, $yield = false) {
        switch ($this->getData()["message_type"]) {
            case "group":
            case "private":
            case "discuss":
                $this->setCache("has_reply", true);
                $data = $this->getData();
                $conn = $this->getConnection();
                switch ($data["message_type"]) {
                    case "group":
                        return (new ZMRobot($conn))->setCallback($yield)->sendGroupMsg($data["group_id"], $msg);
                    case "private":
                        return (new ZMRobot($conn))->setCallback($yield)->sendPrivateMsg($data["user_id"], $msg);
                }
                return null;
        }
        return false;
    }

    public function finalReply($msg, $yield = false) {
        self::$context[$this->cid]["cache"]["block_continue"] = true;
        if ($msg == "") return true;
        return $this->reply($msg, $yield);
    }

    /**
     * @param string $prompt
     * @param int $timeout
     * @param string $timeout_prompt
     * @return string
     * @throws InvalidArgumentException
     * @throws WaitTimeoutException
     */
    public function waitMessage($prompt = "", $timeout = 600, $timeout_prompt = "") {
        if ($prompt != "") $this->reply($prompt);
        if (!isset($this->getData()["user_id"], $this->getData()["message"], $this->getData()["self_id"]))
            throw new InvalidArgumentException("协程等待参数缺失");
        $cid = Co::getuid();
        $api_id = ZMBuf::atomic("wait_msg_id")->get();
        ZMBuf::atomic("wait_msg_id")->add(1);
        $hang = [
            "coroutine" => $cid,
            "user_id" => $this->getData()["user_id"],
            "message" => $this->getData()["message"],
            "self_id" => $this->getData()["self_id"],
            "message_type" => $this->getData()["message_type"],
            "result" => null
        ];
        if ($hang["message_type"] == "group" || $hang["message_type"] == "discuss") {
            $hang[$hang["message_type"] . "_id"] = $this->getData()[$this->getData()["message_type"] . "_id"];
        }
        ZMBuf::appendKey("wait_api", $api_id, $hang);
        $id = swoole_timer_after($timeout * 1000, function () use ($api_id, $timeout_prompt) {
            $r = ZMBuf::get("wait_api")[$api_id] ?? null;
            if ($r !== null) {
                Co::resume($r["coroutine"]);
            }
        });

        Co::suspend();
        $sess = ZMBuf::get("wait_api")[$api_id];
        ZMBuf::unsetByValue("wait_api", $api_id);
        $result = $sess["result"];
        if (isset($id)) swoole_timer_clear($id);
        if ($result === null) throw new WaitTimeoutException($this, $timeout_prompt);
        return $result;
    }

    /**
     * @param $arg
     * @param $mode
     * @param $prompt_msg
     * @return mixed|string
     * @throws InvalidArgumentException
     * @throws WaitTimeoutException
     */
    public function getArgs(&$arg, $mode, $prompt_msg) {
        switch ($mode) {
            case ZM_MATCH_ALL:
                $p = $arg;
                array_shift($p);
                return trim(implode(" ", $p)) == "" ? $this->waitMessage($prompt_msg) : trim(implode(" ", $p));
            case ZM_MATCH_NUMBER:
                foreach ($arg as $k => $v) {
                    if (is_numeric($v)) {
                        array_splice($arg, $k, 1);
                        return $v;
                    }
                }
                return $this->waitMessage($prompt_msg);
            case ZM_MATCH_FIRST:
                if (isset($arg[1])) {
                    $a = $arg[1];
                    array_splice($arg, 1, 1);
                    return $a;
                } else {
                    return $this->waitMessage($prompt_msg);
                }
        }
        throw new InvalidArgumentException();
    }

    public function cloneFromParent() {
        set_coroutine_params(self::$context[Co::getPcid()] ?? self::$context[$this->cid]);
        return context();
    }

    public function copy() { return self::$context[$this->cid]; }
}
