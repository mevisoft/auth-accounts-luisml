<?php

namespace LuisML\AccountsClient;

use RuntimeException;

/**
 * Accounts did not answer in time or answered with a failure that proves nothing about the access.
 */
final class AccountsUnavailable extends RuntimeException {}
