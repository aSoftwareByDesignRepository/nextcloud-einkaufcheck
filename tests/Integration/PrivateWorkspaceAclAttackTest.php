<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Integration;

use OCA\EinkaufCheck\Exception\AccessDeniedException;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCA\EinkaufCheck\Service\AccessControlService;
use OCA\EinkaufCheck\Service\ShoppingListService;
use OCA\EinkaufCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial ACL attacks against private shopping spaces.
 * Requires Nextcloud bootstrap + users admin and ekc_victim (or victim).
 */
class PrivateWorkspaceAclAttackTest extends TestCase {
	use PurgesSoleOwnedWorkspaces;

	private AccessControlService $access;
	private WorkspaceService $workspaces;
	private ShoppingListService $list;
	private string $owner = 'admin';
	private string $attacker = 'ekc_victim';

	protected function setUp(): void {
		if (!class_exists(\OC::class)) {
			self::markTestSkipped('Nextcloud bootstrap unavailable');
		}
		$this->access = \OC::$server->get(AccessControlService::class);
		$this->workspaces = \OC::$server->get(WorkspaceService::class);
		$this->list = \OC::$server->get(ShoppingListService::class);
		$um = \OC::$server->get(\OCP\IUserManager::class);
		if ($um->get($this->attacker) === null) {
			if ($um->get('victim') !== null) {
				$this->attacker = 'victim';
			} else {
				self::markTestSkipped('Need ekc_victim or victim user');
			}
		}
		$this->ensureWorkspaceCreateHeadroom($this->attacker);
		$this->ensureWorkspaceCreateHeadroom($this->owner);
	}

	public function testAppAdminWithoutMembershipCannotReadPrivateSpaceOfVictim(): void {
		// Attacker creates a private space; admin (often NC admin = app admin) must NOT see it
		// unless individually invited.
		$ws = $this->workspaces->createWorkspace(
			$this->attacker,
			'Secret list ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $ws['privacyMode']);
		self::assertNull($this->access->role($id, $this->owner), 'app/NC admin must not get role on foreign private space');
		$this->expectException(AccessDeniedException::class);
		$this->workspaces->getForUser($id, $this->owner);
	}

	public function testAppAdminCannotListItemsInForeignPrivateSpaceViaServiceIdGuess(): void {
		$ws = $this->workspaces->createWorkspace(
			$this->attacker,
			'Secret items ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		$item = $this->list->add($id, $this->attacker, ['name' => 'SECRET_MILK', 'qty' => 1]);
		try {
			$ownerRows = $this->list->list($id, $this->attacker);
			$names = array_map(static fn (array $r): string => (string)$r['name'], $ownerRows);
			self::assertContains('SECRET_MILK', $names);
			self::assertNull($this->access->role($id, $this->owner));
			// Service-layer ACL: guessing the workspace id as app admin must deny.
			$this->expectException(AccessDeniedException::class);
			$this->list->list($id, $this->owner);
		} finally {
			try {
				$this->list->delete($id, $this->attacker, (int)$item['id']);
			} catch (\Throwable) {
			}
		}
	}

	public function testPrivateSpaceRejectsGroupAssignment(): void {
		$ws = $this->workspaces->createWorkspace(
			$this->attacker,
			'No groups ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		$this->expectException(ValidationException::class);
		try {
			$this->workspaces->addGroupMember($id, $this->attacker, [
				'groupId' => 'admin',
				'role' => 'viewer',
			]);
		} catch (ValidationException $e) {
			self::assertSame('private_workspace_groups_forbidden', $e->getErrorCode());
			throw $e;
		}
	}

	public function testLastManagerCannotBeRemoved(): void {
		$ws = $this->workspaces->createWorkspace(
			$this->attacker,
			'Sole manager ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		$members = $this->workspaces->listMembers($id, $this->attacker);
		$managerRow = null;
		foreach ($members as $m) {
			if (($m['type'] ?? '') === 'user' && ($m['role'] ?? '') === 'manager') {
				$managerRow = $m;
				break;
			}
		}
		self::assertNotNull($managerRow);
		$this->expectException(ValidationException::class);
		try {
			$this->workspaces->removeMember((int)$managerRow['id'], $this->attacker);
		} catch (ValidationException $e) {
			self::assertSame('last_manager', $e->getErrorCode());
			throw $e;
		}
	}

	public function testInviteThenVictimSeesSharedListOwnerDoesNotSeeAttackerPrivate(): void {
		// Fresh private space each run — personal workspace may already list the attacker.
		$ownerWs = $this->workspaces->createWorkspace(
			$this->owner,
			'Invite share ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$ownerId = (int)$ownerWs['id'];
		$this->workspaces->addMember($ownerId, $this->owner, [
			'userId' => $this->attacker,
			'role' => 'contributor',
		]);
		$item = $this->list->add($ownerId, $this->owner, ['name' => 'SHARED_BUTTER', 'qty' => 1]);
		try {
			self::assertSame('contributor', $this->access->role($ownerId, $this->attacker));
			$names = array_map(static fn (array $r): string => (string)$r['name'], $this->list->list($ownerId, $this->owner));
			self::assertContains('SHARED_BUTTER', $names);

			$attackerPrivate = $this->workspaces->createWorkspace(
				$this->attacker,
				'Attacker only ' . bin2hex(random_bytes(2)),
				AccessControlService::PRIVACY_PRIVATE,
			);
			$aid = (int)$attackerPrivate['id'];
			self::assertNull($this->access->role($aid, $this->owner));
			$this->expectException(AccessDeniedException::class);
			$this->workspaces->getForUser($aid, $this->owner);
		} finally {
			try {
				$this->list->delete($ownerId, $this->owner, (int)$item['id']);
			} catch (\Throwable) {
			}
		}
	}

	public function testViewerCannotMutateListWhenOnlyViewerRole(): void {
		$ws = $this->workspaces->createWorkspace(
			$this->owner,
			'Viewer gate ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		$this->workspaces->addMember($id, $this->owner, [
			'userId' => $this->attacker,
			'role' => 'viewer',
		]);
		self::assertSame('viewer', $this->access->role($id, $this->attacker));
		$this->expectException(AccessDeniedException::class);
		$this->access->ensureMinimumRole($id, $this->attacker, AccessControlService::ROLE_CONTRIBUTOR);
	}

	public function testDeleteWorkspaceRemovesCascadeAndRepairsPersonal(): void {
		$extra = $this->workspaces->createWorkspace(
			$this->attacker,
			'Disposable ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$extra['id'];
		$this->list->add($id, $this->attacker, ['name' => 'GONE_ITEM', 'qty' => 1]);
		$result = $this->workspaces->deleteWorkspace($id, $this->attacker);
		self::assertTrue($result['ok']);
		self::assertGreaterThan(0, (int)$result['activeWorkspaceId']);
		$this->expectException(AccessDeniedException::class);
		$this->workspaces->getForUser($id, $this->attacker);
	}

	public function testAppAdminCannotDeleteForeignPrivateViaBreakGlass(): void {
		$ws = $this->workspaces->createWorkspace(
			$this->attacker,
			'Not yours ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_PRIVATE,
		);
		$id = (int)$ws['id'];
		$this->expectException(AccessDeniedException::class);
		$this->workspaces->deleteWorkspace($id, $this->owner);
	}

	public function testGhostWorkspaceIdDeniesAppAdmin(): void {
		$this->expectException(AccessDeniedException::class);
		$this->access->ensureMinimumRole(2147483000, $this->owner, AccessControlService::ROLE_VIEWER);
	}

		public function testDirectoryPeerScopeHidesUnrelatedUsersFromPersonalManager(): void {
		$dir = \OC::$server->get(\OCA\EinkaufCheck\Service\DirectorySearchService::class);
		$this->workspaces->ensurePersonalWorkspace($this->attacker);
		$full = $dir->searchUsers('ad', $this->owner, true);
		$peer = $dir->searchUsers('ad', $this->attacker, false);
		$fullIds = array_map(static fn (array $r): string => (string)$r['id'], $full);
		$peerIds = array_map(static fn (array $r): string => (string)$r['id'], $peer);
		foreach ($peerIds as $uid) {
			self::assertTrue($this->access->canUseApp($uid), 'peer results must pass app door');
			$allowed = $this->access->sharesAnyGroup($this->attacker, $uid)
				|| strcasecmp($uid, 'ad') === 0;
			self::assertTrue($allowed, 'peer result ' . $uid . ' must share a group with attacker or be exact uid ad');
		}
		// Full directory for app admin may include users the attacker does not share groups with.
		$extra = array_values(array_diff($fullIds, $peerIds));
		if ($extra !== []) {
			self::assertTrue(true); // scoped strictly smaller — good
		} else {
			// Same set only acceptable when every full hit is already a peer of attacker.
			foreach ($fullIds as $uid) {
				self::assertTrue(
					$this->access->sharesAnyGroup($this->attacker, $uid) || strcasecmp($uid, 'ad') === 0,
					'if peer equals full, every hit must still be a peer of attacker',
				);
			}
		}
	}

public function testPrivacyFlipToPrivateBlockedWhenGroupsPresent(): void {
		if (!$this->access->isAppAdmin($this->owner)) {
			self::markTestSkipped('owner must be app admin to create standard space');
		}
		$ws = $this->workspaces->createWorkspace(
			$this->owner,
			'Standard with group ' . bin2hex(random_bytes(2)),
			AccessControlService::PRIVACY_STANDARD,
		);
		$id = (int)$ws['id'];
		$gm = \OC::$server->get(\OCP\IGroupManager::class);
		$gid = null;
		foreach ($gm->search('') as $g) {
			$gid = $g->getGID();
			break;
		}
		if ($gid === null) {
			self::markTestSkipped('no groups in instance');
		}
		$this->workspaces->addGroupMember($id, $this->owner, [
			'groupId' => $gid,
			'role' => 'viewer',
		]);
		$this->expectException(ValidationException::class);
		try {
			$this->workspaces->updateWorkspace($id, $this->owner, ['privacyMode' => 'private']);
		} catch (ValidationException $e) {
			self::assertSame('workspace_has_group_members', $e->getErrorCode());
			throw $e;
		}
	}
}
