<?php

/**
 * MQTT Client
 *
 * An open source MQTT client library in PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2013 - 2016, sskaje (https://sskaje.me/)
 *
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 *
 * @package    sskaje/mqtt
 * @author     sskaje (https://sskaje.me/)
 * @copyright  Copyright (c) 2013 - 2016, sskaje (https://sskaje.me/)
 * @license    http://opensource.org/licenses/MIT MIT License
 * @link       https://sskaje.me/mqtt/
 */

namespace sskaje\mqtt\Message\Header;
use sskaje\mqtt\Debug;
use sskaje\mqtt\Message;
use sskaje\mqtt\Utility;
use sskaje\mqtt\Exception;


/**
 * Fixed Header definition for PUBLISH
 *
 * @property \sskaje\mqtt\Message\PUBLISH $message
 */
class PUBLISH extends Base
{
    /**
     * Default Flags
     *
     * @var int
     */
    protected $reserved_flags = 0x00;

    /**
     * Required when QoS > 0
     *
     * @var bool
     */
    protected $require_msgid = false;

    protected $dup    = 0; # 1-bit
    protected $qos    = 0; # 2-bit
    protected $retain = 0; # 1-bit

    /**
     * Set DUP
     *
     * @param bool|int $dup
     */
    public function setDup($dup)
    {
        $this->dup = $dup ? 1 : 0;
    }

    /**
     * Get DUP
     *
     * @return int
     */
    public function getDup()
    {
        return $this->dup;
    }

    /**
     * Set QoS
     *
     * @param int $qos 0,1,2
     * @throws Exception
     */
    public function setQos($qos)
    {
        Utility::CheckQoS($qos);
        $this->qos = (int) $qos;

        if ($this->qos > 0) {
            /*
              SUBSCRIBE, UNSUBSCRIBE, and PUBLISH (in cases where QoS > 0) Control Packets MUST contain a
              non-zero 16-bit Packet Identifier [MQTT-2.3.1-1].
             */
            $this->require_msgid = true;
        } else {
            /*
              A PUBLISH Packet MUST NOT contain a Packet Identifier if its QoS value is set to 0 [MQTT-2.3.1-5].
             */
            $this->require_msgid = false;
        }
    }

    /**
     * Get QoS
     *
     * @return int
     */
    public function getQoS()
    {
        return $this->qos;
    }

    /**
     * Set RETAIN
     *
     * @param bool|int $retain
     */
    public function setRetain($retain)
    {
        $this->retain = $retain ? 1 : 0;
    }

    /**
     * Get RETAIN
     *
     * @return int
     */
    public function getRetain()
    {
        return $this->retain;
    }

    /**
     * Set Flags
     *
     * @param int $flags
     * @return bool
     */
    public function setFlags($flags)
    {
        $flags = Utility::ParseFlags($flags);

        $this->setDup($flags['dup']);
        $this->setQos($flags['qos']);
        $this->setRetain($flags['retain']);
        return true;
    }

    /**
     * Set Packet Identifier
     *
     * @param int $msgid
     * @throws Exception
     */
    public function setMsgID($msgid)
    {
        /*
         A PUBLISH Packet MUST NOT contain a Packet Identifier if its QoS value is set to 0 [MQTT-2.3.1-5].
         */
        if ($this->qos) {
            parent::setMsgID($msgid);
        } else if ($msgid) {
            throw new Exception('MsgID MUST NOT be set if QoS is set to 0.');
        }
    }

    /**
     * PUBLISH Variable Header
     *
     * Topic Name, Packet Identifier
     *
     * @return string
     * @throws Exception
     */
    protected function buildVariableHeader()
    {
        $header = '';

        $topic = $this->message->getTopic();
        # Topic
        $header .= Utility::PackStringWithLength($topic);
        Debug::Log(Debug::DEBUG, 'Message PUBLISH: topic='.$topic);

        Debug::Log(Debug::DEBUG, 'Message PUBLISH: QoS='.$this->getQoS());
        Debug::Log(Debug::DEBUG, 'Message PUBLISH: DUP='.$this->getDup());
        Debug::Log(Debug::DEBUG, 'Message PUBLISH: RETAIN='.$this->getRetain());

        # Message ID if QoS > 0
        if ($this->getQoS()) {
            if (!$this->msgid) {
                throw new Exception('MsgID MUST be set if QoS is not 0.');
            }

            $header .= $this->packPacketIdentifer();
        }

        return $header;
    }

    /**
     * Decode Variable Header
     * Topic, Packet Identifier
     *
     * @param string & $packet_data
     * @param int    & $pos
     * @return bool
     */
    protected function decodeVariableHeader(& $packet_data, & $pos)
    {
        $topic = Utility::UnpackStringWithLength($packet_data, $pos);
        $this->message->setTopic($topic);

        if ($this->getQoS() > 0) {
            # Decode Packet Identifier if QoS > 0
            $this->decodePacketIdentifier($packet_data, $pos);
        }

        return true;
    }

    /**
     * Build fixed Header packet
     *
     * @return string
     * @throws Exception
     */
    public function build()
    {
        $flags = 0;

        if (!$this->getQoS()) {
            if ($this->getDup()) {
                /*
                 In the QoS 0 delivery protocol, the Sender MUST send a PUBLISH packet with QoS=0, DUP=0 [MQTT-4.3.1-1].
                 */
                throw new Exception('DUP MUST BE 0 if QoS is 0');
            }
        }

        /**
         * Flags for fixed Header
         *
         * This 4-bit number was defined as DUP,QoS 1,QoS 0,RETAIN in MQTT 3.1,
         * In 3.1.1, only PUBLISH has those names, for PUBREL, SUBSCRIBE, UNSUBSCRIBE: 0010; and others, 0000
         *
         * The definition DUP, QoS, RETAIN works in 3.1.1, and literally,
         * it means the same for PUBREL, SUBSCRIBE and UNSCRIBE.
         */
        $flags |= ($this->dup << 3);
        $flags |= ($this->qos << 1);
        $flags |= $this->retain;

        $this->reserved_flags = $flags;

        return parent::build();
    }
}

# EOF