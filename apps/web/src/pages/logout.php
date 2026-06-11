<?php

declare(strict_types=1);

Auth::logout();
flash('info', 'You are logged out. See you soon!');
redirect('/');
