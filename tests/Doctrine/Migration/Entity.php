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
    public string $id;

}
