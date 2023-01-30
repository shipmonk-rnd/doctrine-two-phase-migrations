<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class Entity
{

    /**
     * @ORM\Id
     * @ORM\Column(type="string", nullable=false)
     */
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

}
