<?php

declare(strict_types=1);

namespace BitWasp\Bitcoin\Serializer\Key\HierarchicalKey;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Key\PublicKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Types;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Buffertools\Exceptions\ParserOutOfRange;
use BitWasp\Buffertools\Parser;

class ExtendedKeySerializer
{
    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var \BitWasp\Buffertools\Types\ByteString
     */
    private $bytestring4;

    /**
     * @var \BitWasp\Buffertools\Types\Uint8
     */
    private $uint8;

    /**
     * @var \BitWasp\Buffertools\Types\Uint32
     */
    private $uint32;

    /**
     * @var \BitWasp\Buffertools\Types\ByteString
     */
    private $bytestring32;

    /**
     * @var \BitWasp\Buffertools\Types\ByteString
     */
    private $bytestring33;

    /**
     * @param EcAdapterInterface $ecAdapter
     * @throws \Exception
     */
    public function __construct(EcAdapterInterface $ecAdapter)
    {
        $this->ecAdapter = $ecAdapter;
        $this->bytestring4 = Types::bytestring(4);
        $this->uint8 = Types::uint8();
        $this->uint32 = Types::uint32();
        $this->bytestring32 = Types::bytestring(32);
        $this->bytestring33 = Types::bytestring(33);
    }

    /**
     * @param NetworkInterface $network
     * @param HierarchicalKey $key
     * @return BufferInterface
     * @throws \Exception
     */
    public function serialize(NetworkInterface $network, HierarchicalKey $key): BufferInterface
    {
        list ($prefix, $data) = $key->isPrivate()
            ? [$network->getHDPrivByte(), new Buffer("\x00". $key->getPrivateKey()->getBinary(), 33)]
            : [$network->getHDPubByte(), $key->getPublicKey()->getBuffer()];

        return new Buffer(
            pack("H*", $prefix) .
            $this->uint8->write($key->getDepth()) .
            $this->uint32->write($key->getFingerprint()) .
            $this->uint32->write($key->getSequence()) .
            $this->bytestring32->write($key->getChainCode()) .
            $this->bytestring33->write($data)
        );
    }

    /**
     * @param NetworkInterface $network
     * @param Parser $parser
     * @return HierarchicalKey
     * @throws ParserOutOfRange
     */
    public function fromParser(NetworkInterface $network, Parser $parser): HierarchicalKey
    {
        try {
            list ($bytes, $depth, $parentFingerprint, $sequence, $chainCode, $keyData) = [
                $this->bytestring4->read($parser),
                (int) $this->uint8->read($parser),
                (int) $this->uint32->read($parser),
                (int) $this->uint32->read($parser),
                $this->bytestring32->read($parser),
                $this->bytestring33->read($parser),
            ];

            $bytes = $bytes->getHex();
        } catch (ParserOutOfRange $e) {
            throw new ParserOutOfRange('Failed to extract HierarchicalKey from parser');
        }

        if ($bytes !== $network->getHDPubByte() && $bytes !== $network->getHDPrivByte()) {
            throw new \InvalidArgumentException('HD key magic bytes do not match network magic bytes');
        }

        $key = ($network->getHDPrivByte() === $bytes)
            ? PrivateKeyFactory::fromBuffer($keyData->slice(1), true, $this->ecAdapter)
            : PublicKeyFactory::fromBuffer($keyData, $this->ecAdapter);

        return new HierarchicalKey($this->ecAdapter, $depth, $parentFingerprint, $sequence, $chainCode, $key);
    }

    /**
     * @param NetworkInterface $network
     * @param BufferInterface $buffer
     * @return HierarchicalKey
     * @throws ParserOutOfRange
     */
    public function parse(NetworkInterface $network, BufferInterface $buffer): HierarchicalKey
    {
        return $this->fromParser($network, new Parser($buffer));
    }
}
