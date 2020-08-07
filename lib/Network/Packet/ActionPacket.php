<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Failure;
use Amp\MultiReasonException;
use Amp\Promise;
use Konfigurator\Network\Packet\AbstractPacket;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\Auth\AuthGuardInterface;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\Network\Session\SessionStorageInterface;
use function Amp\call;

abstract class ActionPacket extends AbstractPacket implements ActionPacketInterface
{
    const FIELD_TYPES = [
        'int', 'integer',
        'float',
        'number', 'numeric',
        'string',
        'bool', 'boolean',
        'null',
        'array', 'object',
    ];

    /** @var array */
    protected array $fields = [];

    /** @var array */
    protected array $fieldProps = [];

    /** @var bool */
    protected bool $isHandledSuccessfully;

    /** @var \Throwable[] */
    protected array $handleExceptions;


    /**
     * ActionPacket constructor.
     * @param SessionInterface $session
     * @param bool $isRemote
     */
    public function __construct(SessionInterface $session, bool $isRemote = false)
    {
        parent::__construct($session, $isRemote);

        $this->isHandledSuccessfully = false;
        $this->handleExceptions = [];

        foreach ($this->getFieldProps() as $field => $props) {

            if (isset($props['rules'])) {
                $rules = $props['rules'];
            } else {
                $rules = $props;
            }

            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            $this->fieldProps[$field] = [
                'rules' => [],
                'serialize' => isset($props['serialize']) && is_callable($props['serialize']) ? $props['serialize'] : null,
                'unserialize' => isset($props['unserialize']) && is_callable($props['unserialize']) ? $props['unserialize'] : null,
                'type' => $props['type'] ?? null,
            ];
            $this->fields[$field] = null;

            foreach ($rules as $rule) {

                if (is_string($rule)) {
                    $rule = explode(':', $rule);
                }

                $rule[0] = strtolower(trim($rule[0]));

                if (!$this->fieldProps[$field]['type']) {
                    if (array_search($rule[0], static::FIELD_TYPES) !== false) {
                        $this->fieldProps[$field]['type'] = $rule[0];
                        continue;
                    }
                }

                switch ($rule[0])
                {
                    case 'required':
                        $this->fieldProps[$field]['rules'][] = function (&$value) {
                            return !is_null($value);
                        };
                        break;
                    case 'trim':
                        $this->fieldProps[$field]['rules'][] = function (&$value) {
                            $value = trim($value);
                            return true;
                        };
                        break;
                    case "strtolower":
                        $this->fieldProps[$field]['rules'][] = function (&$value) {
                            $value = strtolower($value);
                            return true;
                        };
                        break;
                    case "strtoupper":
                        $this->fieldProps[$field]['rules'][] = function (&$value) {
                            $value = strtoupper($value);
                            return true;
                        };
                        break;
                    case "nullable":
                        $this->fieldProps[$field]['rules'][] = function () {
                            return true;
                        };
                        break;
                    default:
                        throw new \LogicException("Unsupported rule {$rule[0]}");
                }
            }
        }
    }

    /**
     * @return mixed
     */
    public static function getId()
    {
        return static::getAction();
    }

    /**
     * @param string $field
     * @return bool
     */
    public function checkField(string $field): bool
    {
        return isset($this->fieldProps[$field]);
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    public function transformField(string $field, $value = null)
    {
        foreach ($this->fieldProps[$field]['rules'] as $rule) {
            if ($rule($value, $this) === false) {
                throw new \LogicException("Rule validation failed!");
            }
        }

        switch ($this->fieldProps[$field]['type'])
        {
            case 'int':
            case 'integer':
                if (!is_int($value)) {
                    $value = intval($value);
                }
                break;
            case 'float':
                if (!is_float($value)) {
                    $value = floatval($value);
                }
                break;
            case 'number':
            case 'numeric':
                if (!is_numeric($value)) {
                    $value = floatval($value);
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    if (is_object($value)) {
                        if (method_exists($value, '__toString')) {
                            $value = $value->__toString();
                        } else if (method_exists($value, 'toString')) {
                            $value = $value->toString();
                        }
                    }
                }
                break;
            case 'bool':
            case 'boolean':
                if (!is_bool($value)) {
                    $value = ( is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (bool) $value );
                }
                break;
            case 'null':
                $value = null;
                break;
            case 'array':
            case 'object':
                if (is_object($value)) {
                    if (method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    } else if (method_exists($value, '__toArray')) {
                        $value = $value->__toArray();
                    }
                }
                break;
        }

        return $value;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return static
     */
    public function setField(string $field, $value = null)
    {
        if (!$this->checkField($field)) {
            throw new \LogicException("Invalid field {$field}!");
        }

        $this->fields[$field] = $value;

        return $this;
    }

    /**
     * @param array|array<string, mixed> $array
     * @return static
     */
    public function setFields(array $array)
    {
        foreach ($array as $k => $v) {
            $this->setField($k, $v);
        }

        return $this;
    }

    /**
     * @param string $field
     * @return mixed
     */
    public function getField(string $field)
    {
        if (!$this->checkField($field)) {
            throw new \LogicException("Invalid field {$field}!");
        }

        if (is_callable($this->fields[$field])) {
            return call($this->fields[$field], $this);
        }

        return $this->fields[$field];
    }

    /**
     * @param array $packet
     * @return Promise<PacketInterface|null>
     */
    public function handle(array $packet): Promise
    {
        return call(static function (self &$self) use ($packet) {

            $response = null;

            try {

                foreach ($self->fieldProps as $field => $arr) {

                    if (isset($arr['unserialize']) && is_object($arr['unserialize']) && $arr['unserialize'] instanceof \Closure) {
                        $value = yield call($arr['unserialize'], $packet[$field] ?? null, $self);
                    } else {
                        $value = $self->transformField($field, $packet[$field] ?? null);
                    }

                    $self->setField($field, $value);
                }

                $response = yield $self->handleSuccess();

                $self->isHandledSuccessfully = true;

            } catch (\Throwable $e) {

                $self->isHandledSuccessfully = false;
                $self->handleExceptions[] = $e;

                try {
                    $response = yield $self->handleFailed();
                } catch (\Throwable $e) {
                    $self->handleExceptions[] = $e;
                }

            }

            return $response;

        }, $this);
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->transform();
    }

    /**
     * @return SessionStorageInterface
     */
    public function getSessionStorage(): SessionStorageInterface
    {
        return $this->getSession()->getStorage();
    }

    /**
     * @return AuthGuardInterface
     */
    public function getAuthGuard(): AuthGuardInterface
    {
        return $this->getSession()->getAuthGuard();
    }

    /**
     * @return Promise<array>
     */
    public function transform(): Promise
    {
        return call(static function (self &$self) {

            try {

                $result = [];

                foreach ($self->fieldProps as $field => $arr) {
                    if (isset($arr['serialize']) && is_callable($arr['serialize'])) {
                        $value = yield call($arr['serialize'], $self->fields[$field], $self);
                    } else {
                        if (is_object($self->fields[$field]) && $self->fields[$field] instanceof \Closure) {
                            $value = yield call($self->fields[$field], $self);
                        } else {
                            $value = $self->transformField($field, $self->fields[$field] ?? null);
                        }
                    }
                    $result[$field] = $value;
                }

                return $result;

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        }, $this);
    }

    /**
     * @return bool
     */
    public function isHandledSuccessfully(): bool
    {
        return $this->isHandledSuccessfully;
    }

    /**
     * @return \Throwable|null
     */
    public function getHandleException(): ?\Throwable
    {
        if (sizeof($this->handleExceptions) > 1) {
            return new MultiReasonException($this->handleExceptions);
        } else if (sizeof($this->handleExceptions) == 1) {
            return $this->handleExceptions[0];
        } else {
            return null;
        }
    }

    /**
     * @param \Throwable $e
     * @return static
     */
    public function attachHandleException(\Throwable $e): self
    {
        $this->handleExceptions[] = $e;
        return $this;
    }

    /**
     * @return Promise
     */
    public function sendPacket(): Promise
    {
        return $this->getSession()->sendPacket($this);
    }

    /**
     * @return array
     */
    public abstract function getFieldProps(): array;

    /**
     * @return Promise<PacketInterface|null>
     */
    protected abstract function handleSuccess(): Promise;

    /**
     * @return Promise<PacketInterface|null>
     */
    protected abstract function handleFailed(): Promise;


}