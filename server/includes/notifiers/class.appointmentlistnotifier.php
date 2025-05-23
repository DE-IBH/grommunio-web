<?php

require_once __DIR__ . '/class.listnotifier.php';

/**
 * AppointmentListNotifier.
 *
 * Generates notifications for changes to the
 * Appointment Folder contents.
 */
class AppointmentListNotifier extends ListNotifier {
	/**
	 * Obtain the list of Message Properties which should be returned
	 * to the client when a Message was changed.
	 *
	 * @return array The properties mapping
	 */
	#[Override]
	protected function getPropertiesList() {
		return $GLOBALS["properties"]->getAppointmentListProperties();
	}

	/**
	 * If an event elsewhere has occurred, it enters in this method. This method
	 * executes one or more actions, depends on the event.
	 *
	 * @param int    $event   event
	 * @param string $entryid entryid
	 * @param mixed  $props
	 */
	#[Override]
	public function update($event, $entryid, $props) {
		switch ($event) {
			case TABLE_SAVE:
				$data = [];

				if (isset($props[PR_STORE_ENTRYID])) {
					$store = $GLOBALS["mapisession"]->openMessageStore($props[PR_STORE_ENTRYID]);
					$properties = $this->getPropertiesList();

					$message = null;
					if (isset($props[PR_ENTRYID])) {
						$message = $GLOBALS["operations"]->openMessage($store, $props[PR_ENTRYID]);
						$messageProps = mapi_getprops($message, $properties);
					}
					else {
						$messageProps = [];
					}

					if (!isset($props[PR_ENTRYID]) || (isset($messageProps[$properties['recurring']]) && $messageProps[$properties['recurring']])) {
						// An object was created inside this folder for which we don't know the entryid
						// this can happen when we copy or move a message. Just tell the javascript that
						// this folder has a new object.
						// Alternatively the recurrence could have been changed, this means that we should send a notification to the client
						// to inform the possibility that a different number of occurrences might be inside his view now.
						$folder = mapi_msgstore_openentry($store, $props[PR_PARENT_ENTRYID]);
						$folderProps = mapi_getprops($folder, [PR_ENTRYID, PR_PARENT_ENTRYID, PR_STORE_ENTRYID, PR_CONTENT_COUNT, PR_CONTENT_UNREAD, PR_DISPLAY_NAME]);

						$data = [
							"item" => [
								[
									"content_count" => $folderProps[PR_CONTENT_COUNT],
									"content_unread" => $folderProps[PR_CONTENT_UNREAD],
									// Add store_entryid,entryid of folder and display_name of folder
									// to JSON data in order to refresh the list.
									"store_entryid" => bin2hex((string) $folderProps[PR_STORE_ENTRYID]),
									"parent_entryid" => bin2hex((string) $folderProps[PR_PARENT_ENTRYID]),
									"entryid" => bin2hex((string) $folderProps[PR_ENTRYID]),
									"display_name" => $folderProps[PR_DISPLAY_NAME],
								],
							],
						];

						$this->addNotificationActionData("newobject", $data);
					}
					else {
						$data = $GLOBALS["operations"]->getMessageProps($store, $message, $properties);
						/*
						 * We dose't need these properties in AppointmentListNotifier,
						 * because if we send this properties in AppointmentListNotifier
						 * webapp use this properties and try to re-draw appointment in calendar
						 * which look annoying when user drag and drop same appointment frequently
						 * in calendar (related to WA-8897).
						 */
						unset($data['props']['startdate'], $data['props']['duedate'], $data['props']['commonstart'], $data['props']['commonend']);

						$this->addNotificationActionData("update", ["item" => [$data]]);
					}

					$GLOBALS["bus"]->addData($this->createNotificationResponseData());
				}
				break;

			case TABLE_DELETE:
				parent::update($event, $entryid, $props);
				break;
		}
	}
}
