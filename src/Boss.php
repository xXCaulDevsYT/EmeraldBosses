<?php
declare(strict_types=1);
namespace MiniBosses;

use pocketmine\entity\Creature;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\Player;
use pocketmine\utils\UUID;

class Boss extends Creature {

	const NETWORK_ID = 1000;
	/** @var int */
	protected $networkId = 32;
	/** @var Player|null */
	protected $target;
	/** @var Position */
	protected $spawnPos;
	/** @var float|int */
	protected $attackDamage = 1;
	/** @var int */
	protected $attackRate = 10;
	/** @var int */
	protected $attackDelay = 0;
	/** @var int */
	protected $speed;
	/** @var Item[][] */
	protected $drops = [];
	/** @var int */
	protected $respawnTime;
	/** @var string */
	protected $skin;
	/** @var Item */
	protected $heldItem;
	/** @var float */
	protected $range;
	/** @var int */
	protected $knockbackTicks = 0;
	/** @var float|int */
	protected $scale;
	/** @var Main */
	protected $plugin;

	public function __construct(Level $level, CompoundTag $nbt) {
		$this->networkId = (int) $nbt->getInt("networkId");
		$this->width = Data::WIDTHS[$this->networkId];
		$this->height = Data::HEIGHTS[$this->networkId];
		parent::__construct($level, $nbt);
		$this->range = $this->namedtag->getFloat("range");
		$this->spawnPos = new Position($this->namedtag->getListTag("spawnPos")[0], $this->namedtag->getListTag("spawnPos")[1], $this->namedtag->getListTag("spawnPos")[2], $this->level);
		$this->attackDamage = $this->namedtag->getFloat("attackDamage");
		$this->attackRate = $this->namedtag->getInt("attackRate");
		$this->speed = $this->namedtag->getInt("speed");
		$this->scale = $this->namedtag->getFloat("scale") ?? 1;
		if($this->namedtag->getString("drops") !== "") {
			foreach(explode(' ', $this->namedtag->getString("drops")) as $item) {
				$item = explode(';', $item);
				$this->drops[] = [Item::get($item[0], $item[1] ?? 0, $item[2] ?? 1, $item[3] ?? ""), $item[4] ?? 100];
			}
		}
		$this->respawnTime = $this->namedtag->getInt("respawnTime");
		$this->skin = $this->namedtag->getString("skin");
		if($this->namedtag->getString("heldItem") !== "") {
			$heldItem = explode(';', $this->namedtag->getString("heldItem"));
			$this->heldItem = Item::get($heldItem[0], $heldItem[1] ?? 0, $heldItem[2] ?? 1, $heldItem[3] ?? "");
		}else $this->heldItem = Item::get(0);
	}

	public function initEntity() : void {
		$this->plugin = $this->server->getPluginManager()->getPlugin("MiniBosses");
		parent::initEntity();
		$this->setImmobile(true);
		$this->setScale($this->namedtag->getFloat("scale"));
		if($this->namedtag->getInt("maxHealth") !== null) {
			parent::setMaxHealth($this->namedtag->getInt("maxHealth"));
			$this->setHealth($this->namedtag->getInt("maxHealth"));
		}else {
			$this->setMaxHealth(20);
			$this->setHealth(20);
		}
	}

	public function setMaxHealth($health) : void {
		$this->namedtag->setInt("maxHealth", $health);
		parent::setMaxHealth($health);
	}

	public function spawnTo(Player $player) : void {
		parent::spawnTo($player);
		if($this->networkId === 63) {
			$pk = new AddPlayerPacket();
			$pk->uuid = UUID::fromData((string) $this->getId(), $this->skin, $this->getNameTag());
			$pk->username = $this->getName();
			$pk->entityRuntimeId = $this->getId();
			$pk->position = $this->asPosition();
			$pk->motion = $this->motion;
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->item = $this->heldItem;
			$pk->metadata = $this->propertyManager->getAll();
			$player->dataPacket($pk);
		}else {
			$pk = new AddEntityPacket();
			$pk->entityRuntimeId = $this->getID();
			$pk->type = $this->networkId;
			$pk->position = $this->asPosition();
			$pk->motion = new Vector3();
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->metadata = $this->propertyManager->getAll();
			$player->dataPacket($pk);
			if($this->heldItem->getId() > 0) {
				$pk = new MobEquipmentPacket();
				$pk->entityRuntimeId = $this->getId();
				$pk->item = $this->heldItem;
				$pk->inventorySlot = 0;
				$pk->hotbarSlot = 0;
				$player->dataPacket($pk);
			}
		}
	}

	public function getName() : string {
		return $this->getNameTag();
	}

	public function saveNBT() : void {
		parent::saveNBT();
		$this->namedtag->setInt("maxHealth", $this->getMaxHealth());
		$this->namedtag->setTag(new ListTag("spawnPos", [new DoubleTag("", $this->spawnPos->x), new DoubleTag("", $this->spawnPos->y), new DoubleTag("", $this->spawnPos->z)]));
		$this->namedtag->setFloat("range", $this->range);
		$this->namedtag->setFloat("attackDamage", $this->attackDamage);
		$this->namedtag->setInt("networkId", $this->networkId);
		$this->namedtag->setInt("attackRate", $this->attackRate);
		$this->namedtag->setInt("speed", $this->speed);
		$drops2 = [];
		foreach($this->drops as $drop) {
			$drops2[] = $drop[0]->getId().";".$drop[0]->getDamage().";".$drop[0]->getCount().";".$drop[0]->getCompoundTag().";".$drop[1];
		}
		$this->namedtag->setString("drops", implode(' ', $drops2));
		$this->namedtag->setInt("respawnTime", $this->respawnTime);
		$this->namedtag->setString("skin", $this->skin);
		$this->namedtag->setString("heldItem", ($this->heldItem instanceof Item ? $this->heldItem->getId().";".$this->heldItem->getDamage().";".$this->heldItem->getCount().";".$this->heldItem->getCompoundTag() : ""));
		$this->namedtag->setFloat("scale", $this->scale);
	}

	public function onUpdate($currentTick) : bool {
		if($this->knockbackTicks > 0) {
			$this->knockbackTicks--;
		}
		if(($player = $this->target) and $player->isAlive()) {
			if($this->distanceSquared($this->spawnPos) > $this->range) {
				$this->setPosition($this->spawnPos);
				$this->setHealth($this->getMaxHealth());
				$this->target = null;
			}else {
				if(!$this->onGround) {
					if($this->motion->y > -$this->gravity * 4) {
						$this->motion->y = -$this->gravity * 4;
					}else {
						$this->motion->y -= $this->gravity;
					}
					$this->move($this->motion->x, $this->motion->y, $this->motion->z);
				}elseif($this->knockbackTicks > 0) {

				}else {
					$x = $player->x - $this->x;
					$y = $player->y - $this->y;
					$z = $player->z - $this->z;
					if($x ** 2 + $z ** 2 < 0.7) {
						$this->motion->x = 0;
						$this->motion->z = 0;
					}else {
						$diff = abs($x) + abs($z);
						$this->motion->x = $this->speed * 0.15 * ($x / $diff);
						$this->motion->z = $this->speed * 0.15 * ($z / $diff);
					}
					$this->yaw = rad2deg(atan2(-$x, $z));
					if($this->networkId === 53) {#enderdragon
						$this->yaw += 180;
					}
					$this->pitch = rad2deg(atan(-$y));
					$this->move($this->motion->x, $this->motion->y, $this->motion->z);
					if($this->distanceSquared($this->target) < $this->scale and $this->attackDelay++ > $this->attackRate) {
						$this->attackDelay = 0;
						$ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->attackDamage);
						$player->attack($ev);
					}
				}
			}
		}
		$this->updateMovement();
		parent::onUpdate($currentTick);
		return !$this->closed;
	}

	public function attack(EntityDamageEvent $source) : void {
		if(!$source->isCancelled() and $source instanceof EntityDamageByEntityEvent) {
			$dmg = $source->getDamager();
			if($dmg instanceof Player) {
				$this->target = $dmg;
				parent::attack($source);
				$this->motion->x = ($this->x - $dmg->x) * 0.19;
				$this->motion->y = 0.5;
				$this->motion->z = ($this->z - $dmg->z) * 0.19;
				$this->knockbackTicks = 10;
			}
		}
	}

	public function kill() : void {
		parent::kill();
		$this->plugin->respawn($this->getNameTag(), $this->respawnTime);
	}

	public function getDrops() : array {
		$drops = [];
		foreach($this->drops as $drop) {
			if(mt_rand(1, 100) <= $drop[1]) {
				$drops[] = $drop[0];
			}
		}
		return $drops;
	}
}
