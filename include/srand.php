<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Historically this file bundled a userland entropy collector (by George
 * Argyros) with fallbacks through openssl, mcrypt and /dev/urandom for
 * PHP < 7. This fork requires PHP >= 8.1, where the native CSPRNG is
 * always available, so secure_random_bytes() is now a thin wrapper kept
 * only for backwards compatibility with existing callers and mods.
 */
function secure_random_bytes($len = 10)
{
	return random_bytes($len);
}
