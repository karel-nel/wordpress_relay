=== Refinery Relay ===
Contributors: refinery-relay
Tags: relay, search, chatbot, content feed
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT

A secure content feed and direct-widget integration for Nimble Relay.

== Description ==

Refinery Relay lets the hosted Relay service ingest published WordPress content through an authenticated HTTP feed. Choose any public post types and paste Relay's direct widget markup from the Chat widget section.

This port intentionally does not include the reply widget. It does not store conversations in WordPress or expose them on the website.

== Installation ==

1. Upload the plugin and activate it.
2. Visit Settings > Relay and choose the content sources Relay should ingest.
3. Copy the feed URL and bearer token into a Relay HTTP feed.
4. Paste the public direct-widget embed code supplied by Relay.

== Security ==

The document feed requires a 64-character bearer token. Generating a replacement immediately invalidates the old token. Widget scripts are restricted to the `relay.niimble.io` host. The plugin stores no visitor conversations.
