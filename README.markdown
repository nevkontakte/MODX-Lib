# MODX Library

MODX Library is a core part of phpBB Constructor project and provides processing phpBB MOD install scripts in [MODX](http://www.phpbb.com/mods/modx/) format.

It's designed as stand-alone library, which can be easily integrated to any project.

## Standard conformance

Library provides full support for MODX 1.2.5 except following operations:

* edit → action[type=operation]

## Implementation details

Because of phpBB Constructor needs, MODX Library has special ways of processing &lt;php-installer&gt; and &lt;sql&gt; instructions.

### &lt;php-installer&gt;

MODX Library add line to install/mods/installer.txt file with path to installer file, which should be ran after installation.

It also adds

	define('IN_INSTALL', true);

line to installer file beginning if it doesn't contain such line already.

### &lt;sql&gt;

Saves query to file `install/mods/$dbms.sql`, where `$dbms` is value of attribute `dbms` or `sql-parser` if it's not specified.