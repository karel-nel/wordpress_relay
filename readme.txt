=== Refinery Relay ===
Contributors: refinery-relay
Tags: support, contact, inbox, feedback
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT

A self-hosted customer message inbox and contact widget.

== Description ==

Refinery Relay stores messages in WordPress, notifies the configured address, and provides an inbox with open, pending, and closed states. Enable the floating contact widget or add `[refinery_relay]` to a page.

This port intentionally does not include the visitor reply widget. Follow-up can be handled through the visitor's email address shown in the inbox.

== Installation ==

1. Upload the plugin and activate it.
2. Visit Relay > Settings.
3. Configure the public widget and notification address.

== Privacy ==

Relay stores the submitted name, email, subject, message, page URL, user agent, and a one-way IP hash. It integrates with WordPress personal-data export and erasure tools. Set `REFINERY_RELAY_REMOVE_DATA` to true before uninstalling to remove all plugin data.
