<?php

require "./config.php";

/**
 * @see https://github.com/cfinke/amazon-alexa-php
 */
require "../lib/amazon-alexa-php/src/Request/Request.php";
require "../lib/amazon-alexa-php/src/Request/Application.php";
require "../lib/amazon-alexa-php/src/Request/Certificate.php";
require "../lib/amazon-alexa-php/src/Request/IntentRequest.php";
require "../lib/amazon-alexa-php/src/Request/LaunchRequest.php";
require "../lib/amazon-alexa-php/src/Request/Session.php";
require "../lib/amazon-alexa-php/src/Request/SessionEndedRequest.php";
require "../lib/amazon-alexa-php/src/Request/User.php";
require "../lib/amazon-alexa-php/src/Response/Response.php";
require "../lib/amazon-alexa-php/src/Response/OutputSpeech.php";
require "../lib/amazon-alexa-php/src/Response/Card.php";
require "../lib/amazon-alexa-php/src/Response/Reprompt.php";

ob_start();

$raw_request = file_get_contents( "php://input" );

try {
	$alexa = new \Alexa\Request\Request( $raw_request, APPLICATION_ID );
	
	// Generate the right type of Request object
	$request = $alexa->fromData();

	$response = new \Alexa\Response\Response;
	
	// By default, always end the session unless there's a reason not to.
	$response->shouldEndSession = true;

	if ( 'LaunchRequest' === $request->data['request']['type'] ) {
		// Just opening the skill ("Open Blog Helper") responds with the instructions.
		// An argument could be made that this should instead be the same as the GetNotifications intent.
		handleIntent( $request, $response, 'AMAZON.HelpIntent' );
	}
	else {
		handleIntent( $request, $response, $request->intentName );
	}

	// A quirk of the library -- you need to call respond() to set up the final internal data for the response, but this has no output.
	$response->respond();

	echo json_encode( $response->render() );
} catch ( Exception $e ) {
	header( "HTTP/1.1 400 Bad Request" );
	exit;
}

$output = ob_get_clean();

ob_end_flush();

header( 'Content-Type: application/json' );
echo $output;
exit;

/**
 * Make a request to the WordPress.com API.
 *
 * @param string $oauth_token An access token with access to a single WordPress.com blog.
 * @param string $path The API endpoint path to call. Starts with a slash and the API version.
 * @param string $method GET or POST
 * @param array $args Request parameters, keyed by param name.
 * @return object The API response.
 */
function wpcom_api_request( $oauth_token, $path, $method = "GET", $args = array() ) {
	$query = '';

	$options = array(
		'http' => array(
			'ignore_errors' => true, // This does not actually ignore errors, it just causes the body content to still be retrieved on non-200 status codes.
			'header' => array( 'Authorization: Bearer ' . $oauth_token ),
		),
	);

	if ( 'POST' === $method ) {
		$options['http']['method'] = 'POST';
		$options['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded';
		$options['http']['content'] = http_build_query( $args );
	}

	if ( 'GET' === $method ) {
		if ( ! empty( $args ) ) {
			$query = '?';
			
			foreach ( $args as $key => $val ) {
				$query .= urlencode( $key ) . "=" . urlencode( $val ) . "&";
			}
		}
	}

	$context = stream_context_create( $options );

	$response = file_get_contents( 'https://public-api.wordpress.com/rest' . $path . $query, false, $context );

	$response = json_decode( $response );

	return $response;
}

function state_file( $session_id ) {
	$state_dir = dirname( __FILE__ ) . "/state";
	
	$state_file = $state_dir . "/" . $session_id;
	
	touch( $state_file );
	
	if ( realpath( $state_file ) != $state_file ) {
		// Possible path traversal.
		return false;
	}
	
	return $state_file;
}

/**
 * Save the state of the session so that intents that rely on the previous response can function.
 *
 * @param string $session_id
 * @param mixed $state
 */
function save_state( $session_id, $state ) {
	$state_file = state_file( $session_id );

	if ( ! $state_file ) {
		return false;
	}
	
	if ( ! $state ) {
		if ( file_exists( $state_file ) ) {
			unlink( $state_file );
		}
	}
	else {
		file_put_contents( $state_file, json_encode( $state ) );
	}
}

/**
 * Get the current state of the session.
 *
 * @param string $session_id
 * @return object
 */
function get_state( $session_id ) {
	$state_file = state_file( $session_id );

	if ( ! $state_file ) {
		return false;
	}
	
	if ( ! file_exists( $state_file ) ) {
		return false;
	}

	return json_decode( file_get_contents( $state_file ) );
}

/**
 * Get the site ID that the token has access to.
 *
 * @param string $oauth_token
 * @return int $site_id
 */
function get_site( $oauth_token ) {
	$response = wpcom_api_request( $oauth_token, "/v1/me" );
	$site_id = $response->token_site_id;

	return $site_id;
}

/** 
 * Given an intent, handle all processing and response generation.
 * This is split up because one intent can lead into another; for example,
 * moderating a comment immediately launches the next step of the NewComments
 * intent.
 *
 * @param object $request The Request.
 * @param object $response The Response.
 * @param string $intent The intent to handle, regardless of $request->intentName
 */
function handleIntent( &$request, &$response, $intent ) {
	$oauth_token = $request->data['session']['user']['accessToken'];
	
	if ( ! $oauth_token && 'AMAZON.HelpIntent' != $intent ) {
		$response->addOutput( 'Please open the Alexa app and link your WordPress.com account.' );
		$response->withCard( 'Link your account' );
		$response->card->type = 'LinkAccount';
		return;
	}
	
	$session_id = $request->data['session']['sessionId'];
	$state = get_state( $session_id );

	if ( ! $request->sesssion->new ) {
		// These intents have no standalone processing of their own; they are just
		// confirmations for other intents.
		switch ( $intent ) {
			case 'AMAZON.YesIntent':
				$intent = $state->last_request->intentName;
			break;
			case 'AMAZON.NoIntent':
				$response->addOutput( "Ok." );
				return;
			break;
			case 'AMAZON.StopIntent':
			case 'AMAZON.CancelIntent':
				return;
			break;
		}
	}

	switch ( $intent ) {
		case 'GetNotifications':
			// Read the user's notifications to them.
			$args = array();
			$args['unread'] = true;
			$args['fields'] = "id,type,subject,timestamp";
			$args['number'] = 100; // If a user has more than 100 notifications, they'll continually get the 100th newest notification until they clear out the backlog.

			$notifications_response = wpcom_api_request( $oauth_token, "/v1.1/notifications", "GET", $args );

			if ( ! $notifications_response || $notifications_response->error ) {
				$response->addOutput( "An error occurred while retrieving your notifications." );

				if ( $notifications_response && $notifications_response->message ) {
					$response->addOutput( $notifications_response->message );
				}

				return;
			}

			if ( ! $notifications_response || empty( $notifications_response->notes ) ) {
				$response->addOutput( "You have no new notifications." );
				return;
			}

			$notification_count = count( $notifications_response->notes );
			
			// Respond with the oldest unread notification.
			$notification = array_pop( $notifications_response->notes );

			if ( $request->session->new ) {
				// For continuing sessions, don't repeat introductions.
				if ( $notification_count === 1 ) {
					$response->addOutput( "You have one new notification." );
				}
				else {
					$response->addOutput( "You have " . $notification_count . " new notifications. Here's the first one." );
				}
			}

			// The notification is structured with multiple subjects that appear to all work as natural text descriptions of the notification.
			// A comment notification might have two subjects: "So-and-so commented on your post." and their comment: "This is a great post!"
			foreach ( $notification->subject as $subsubject ) {
				$response->addOutput( $subsubject->text );
			}

			// Ask for permission to continue. Otherwise, if the user has 20 new notifications but doesn't want to listen to all of them, we
			// don't know how many to mark as read.
			if ( $notification_count > 1 ) {
				$response->addOutput( "Shall I continue?" );
				save_state( $session_id, array( 'last_request' => $request ) );
				$response->shouldEndSession = false;
			}

			// Mark the notification as read. This is how Calypso does it.
			wpcom_api_request( $oauth_token, "/v1.1/notifications/read", "POST", array( "counts[" . $notification->id . "]" => 9999 ) );
		break;
		case 'NewPost':
			// Create a new draft post.
			$site_id = get_site( $oauth_token );
			$title = $request->getSlot( 'Title' );

			if ( $title ) {
				$post_creation_response = wpcom_api_request( $oauth_token, "/v1.2/sites/" . $site_id . "/posts/new", "POST", array( 'title' => $title, 'status' => 'draft' ) );
				
				if ( ! $post_creation_response || ! isset( $post_creation_response->ID ) || $post_creation_response->error ) {
					$response->addOutput( "An error occurred while trying to create your blog post." );
					
					if ( $comments_response && $comments_response->message ) {
						$response->addOutput( $post_creation_response->error );
					}
				}
				else {
					$response->addOutput( "I've created a draft post with the title " . $title );
					$response->addCardTitle( "New Blog Post Draft" );
					$response->addCardOutput( 'I created a new post for you with the title "' . $title . '".' );
					$response->addCardOutput( 'You can continue editing it at WordPress.com.' );
					// Links aren't supported in 3rd-party cards yet.
					// $response->addCardOutput( 'You can continue editing it at https://wordpress.com/post/' . $site_id . '/' . $post_creation_response->ID );
				}
			}
			else {
				$response->addOutput( "I couldn't quite understand that. Could you repeat it?" );
				$response->shouldEndSession = false;
			}
		break;
		case 'NewComments':
			// Check for comments in moderation.
			$site_id = get_site( $oauth_token );

			$args = array();
			$args['status'] = 'unapproved';

			$comments_response = wpcom_api_request( $oauth_token, "/v1.1/sites/" . $site_id . "/comments", "GET", $args );

			if ( ! $comments_response || $comments_response->error ) {
				$response->addOutput( "An error occurred while trying to retrieve your pending comments." );

				if ( $comments_response && $comments_response->message ) {
					$response->addOutput( $comments_response->message );
				}

				return;
			}

			$comment_count = count( $comments_response->comments );

			if ( $comment_count === 0 ) {
				if ( $request->session->new ) {
					$response->addOutput( "You don't have any comments to moderate." );
				}
				else {
					$response->addOutput( "You don't have any more comments to moderate." );
				}
			}
			else {
				if ( $request->session->new ) {
					if ( $comment_count === 1 ) {
						$response->addOutput( "I found one pending comment." );
					}
					else {
						$response->addOutput( "I found " . count ( $comments_response->comments ) . " pending comments. Here's the first one:" );
					}
				}
				else {
					$response->addOutput( "Here's the next pending comment." );
				}

				$comment = $comments_response->comments[0];

				$response->addOutput( $comment->author->name . " commented on your post " . $comment->post->title . " and said " . trim( strip_tags( $comment->content ) ) . "." );
				$response->addOutput( "Do you want to approve this comment, delete it, or should I mark it as spam?" );
				$response->shouldEndSession = false;
				save_state( $session_id, array( 'comment_ID' => $comment->ID, 'last_response' => $response, 'site_id' => $site_id ) );
			}
		break;
		case 'ApproveComment':
		case 'TrashComment':
		case 'SpamComment':
			// Moderate a comment that was read via the NewComments intent.
			$site_id = $state->site_id;
			$comment_id = $state->comment_ID;

			if ( ! $comment_id ) {
				if ( 'TrashComment' === $intent ) {
					// Amazon keeps interpeting 'cancel' and 'never mind' as a TrashComment intent, not a CancelIntent.
				}
				else {
					// If the user says just "approve it" or "mark as spam" without first having run the NewComments intent.
					$response->addOutput( "I'm afraid I don't know what comment you're referring to." );
				}
				
				return;
			}

			$args = array();

			switch ( $intent ) {
				case 'ApproveComment':
					$args['status'] = 'approved';
				break;
				case 'TrashComment':
					$args['status'] = 'trash';
				break;
				case 'SpamComment':
					$args['status'] = 'spam';
				break;
			}

			$moderation_response = wpcom_api_request( $oauth_token, "/v1.1/sites/" . $site_id . "/comments/" . $comment_id, "POST", $args );

			if ( ! $moderation_response || $moderation_response->error ) {
				$response->addOutput( "An error occurred while trying to moderate the comment." );

				if ( $moderation_response && $moderation_response->message ) {
					$response->addOutput( $moderation_response->message );
				}

				return;
			}

			switch ( $intent ) {
				case 'ApproveComment':
					$response->addOutput( "I've approved the comment." );
				break;
				case 'TrashComment':
					$response->addOutput( "I've put the comment in the trash." );
				break;
				case 'SpamComment':
					$response->addOutput( "I've marked the comment as spam." );
				break;
			}

			// Go to next comment in list.
			$request->session->new = false;
			return handleIntent( $request, $response, "NewComments" );
		break;
		case 'AMAZON.HelpIntent':
			$response->addOutput( "Here are some things you can say:" );
			$response->addOutput( "Check my notifications" );
			$response->addOutput( "Do I have any comments to moderate?" );
			$response->addOutput( "Save a draft post called 'Summertime Fun'" );
			$response->addOutput( "You can also say stop if you're done. So, how can I help?" );

			$response->addCardTitle( "Using Blog Helper" );
			$response->addCardOutput( "Try these example phrases:" );
			$response->addCardOutput( "Alexa, ask Blog Helper if I have any new notifications." );
			$response->addCardOutput( "Alexa, open Blog Helper and see if I have any new comments." );
			$response->addCardOutput( "Alexa, start a new post titled 'I love blogging with my voice.'" );
			$response->addCardOutput( "(I'll always save your posts as drafts so you can review them before publishing.)" );

			$response->shouldEndSession = false;
		break;
		case 'AMAZON.RepeatIntent':
			if ( ! $state || ! $state->last_response ) {
				$response->addOutput( "I'm sorry, I don't know what to repeat." );
			}
			else {
				save_state( $session_id, $state );
				$response->shouldEndSession = false;
				$response->output = $state->last_response->output;
				$response->shouldEndSession = false;
			}
		break;
	}
}