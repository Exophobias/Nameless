<?php

class SyncDiscordRolesEndpoint extends KeyAuthEndpoint {

    public function __construct() {
        $this->_route = 'discord/{user}/sync-roles';
        $this->_module = 'Discord Integration';
        $this->_description = 'Set a NamelessMC user\'s according to the supplied Discord Role ID list';
        $this->_method = 'POST';
    }

    public function execute(Nameless2API $api, User $user): void {
        $api->validateParams($_POST, []);

        if (!Discord::isBotSetup()) {
            $api->throwError(DiscordApiErrors::ERROR_DISCORD_INTEGRATION_DISABLED);
        }

        $added = $_POST['add'] ?? [];
        $removed = $_POST['remove'] ?? [];
        if (!is_array($added) || !is_array($removed)) {
            $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
        }
        $discordStaffRoles = [
            '665323124895645725' => true, // Helper
            '665323333876973589' => true, // Moderator
            '665322860578996254' => true, // Administrator
            '657096201665249312' => true, // Senior Administrator
        ];
        $changesStaff = false;
        foreach (array_merge($added, $removed) as $roleId) {
            if (!is_string($roleId) && !is_int($roleId)) {
                $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
            }
            if (!preg_match('/^[1-9][0-9]{16,19}$/D', (string) $roleId)) {
                $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS);
            }
            $changesStaff = $changesStaff || isset($discordStaffRoles[(string) $roleId]);
        }

        if ($changesStaff) {
            $expectedDiscordId = $_POST['discord_id'] ?? null;
            if (!is_string($expectedDiscordId) || !preg_match('/^[0-9]{17,20}$/D', $expectedDiscordId)) {
                $api->throwError(Nameless2API::ERROR_INVALID_POST_CONTENTS,
                    'Staff role changes require the source Discord user ID.');
            }
            // Serialize staff deltas with verified-link unlink. An event for a former
            // Discord account must not apply to a newly linked account on this forum user.
            $pdo = DB::getInstance()->getPDO();
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                $api->throwError(Nameless2API::ERROR_UNKNOWN_ERROR, 'Staff role sync unavailable.', 503);
            }
            try {
                $lookup = $pdo->prepare(
                    "SELECT ui.identifier FROM nl2_users_integrations ui
                     JOIN nl2_integrations i ON i.id = ui.integration_id
                     WHERE ui.user_id = ? AND i.name = 'Discord' AND ui.verified = 1 FOR UPDATE"
                );
                if (!$lookup || !$lookup->execute([$user->data()->id])) {
                    throw new RuntimeException('Cannot verify Discord role event identity.');
                }
                $links = $lookup->fetchAll(PDO::FETCH_COLUMN);
                if (count($links) !== 1 || (string) $links[0] !== $expectedDiscordId) {
                    throw new DomainException('Discord role event belongs to an unlinked or changed identity.');
                }
                $log_array = GroupSyncManager::getInstance()->broadcastGroupChange(
                    $user, DiscordGroupSyncInjector::class, $added, $removed
                );
                if (!$pdo->commit()) {
                    throw new RuntimeException('Cannot commit staff role sync.');
                }
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($error instanceof DomainException) {
                    $api->throwError(Nameless2API::ERROR_NOT_AUTHORIZED, $error->getMessage(), 409);
                }
                error_log('Staff role sync unavailable: ' . get_class($error));
                $api->throwError(Nameless2API::ERROR_UNKNOWN_ERROR, 'Staff role sync unavailable.', 503);
            }
        } else {
            $log_array = GroupSyncManager::getInstance()->broadcastGroupChange(
                $user, DiscordGroupSyncInjector::class, $added, $removed
            );
        }

        if (count($log_array)) {
            Log::getInstance()->log(Log::Action('discord/role_set'), json_encode($log_array), $user->data()->id);
        }

        $api->returnArray(array_merge(['message' => Discord::getLanguageTerm('group_updated')], $log_array));
    }
}
