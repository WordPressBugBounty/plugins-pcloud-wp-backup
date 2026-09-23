/**
 * PCloud WP backup plugin - JavaScript file.
 *
 * @package pcloud_wp_backup
 */

// Everything below is wrapped in a function scope on purpose: as a classic script, top-level
// `let`/`function` declarations (notably `__`) would share the page's global lexical scope and
// collide with other plugins' scripts that declare the same names (e.g. `const { __ } = wp.i18n`).
( function () {

php_data      = (typeof php_data !== "undefined") ? php_data : {};
globalLang    = (typeof globalLang !== "undefined") ? globalLang : {};
pCloudGlobals = (typeof pCloudGlobals !== "undefined") ? pCloudGlobals : {};

let transl      = {};
let defaultLang = 'en';
let currentLang = defaultLang;

if (typeof String.prototype.contains === 'undefined') {
	String.prototype.contains = function (it) {
		return this.indexOf( it ) !== -1;
	};
}

function __(key, str, repl)
{
	if (currentLang in globalLang && key in globalLang[currentLang]) {
		return _repl( globalLang[currentLang][key] );
	} else if (defaultLang in globalLang && key in globalLang[defaultLang]) {
		return _repl( globalLang[defaultLang][key] );
	} else if (str) {
		return _repl( str );
	} else {
		return _repl( key );
	}

	function _repl(str)
	{
		for (let n in repl) {
			str = str.replace( '%' + n + '%', repl[n] );
		}

		return str;
	}
}

if (typeof jQuery !== "undefined") {
	$ = jQuery;
}

jQuery(
	function ($) {

		let pluginURL        = '';
		let wp2pcl_nonce     = '';
		let wp2pMainBlk      = $( '#wp2pcloud' );
		let wp2pcl_debugmode = false;

		if (wp2pMainBlk.length > 0) {
			let tmpLng = wp2pMainBlk.attr( 'data-lang' );
			if (tmpLng.match( /-/ )) {
				let tmpLngArr = tmpLng.split( '-' );
				currentLang   = tmpLngArr[0].toLowerCase();
			} else if (tmpLng.match( /_/ )) {
				let tmpLngArr = tmpLng.split( '_' );
				currentLang   = tmpLngArr[0].toLowerCase();
			} else {
				currentLang = tmpLng.toLowerCase();
			}
			if (currentLang.length !== 2) {
				currentLang = 'en';
			}

			pluginURL    = wp2pMainBlk.attr( 'data-pluginurl' );
			wp2pcl_nonce = wp2pMainBlk.attr( 'data-nonce' );
		}

		if (pluginURL.length > 0) {
			$.getJSON(
				pluginURL + "assets/translate.json?v=" + encodeURIComponent( php_data.plugin_version || '2.0.01' ),
				function (json) {
					if (typeof json['pcl_lang'] !== "undefined") {
						transl = json['pcl_lang'];
						setTranslations();
					}
				}
			);
		}

		const ajax_url        = 'admin-ajax.php?action=pcloudbackup';
		let backupOffsetChunk = 0;
		let log_reader_timer  = 2000; // ms.

		/**
		 * LOGIN PROCEDURE!
		 */

		if ($( '.wp2pcloud-login-succcess' ).length > 0) {

			let newURL = top.location.href.slice( 0, top.location.href.indexOf( '&' ) );

			window.setTimeout(
				function () {
					top.location.href = newURL;
				},
				4000
			);

		}

		/**
		 * Apply translations to the elements
		 */
		function setTranslations()
		{
			$( '.pcl_transl' ).each(
				function (i, elm) {

					if (typeof transl[currentLang] === "undefined") {
						return false;
					}

					let tr_key = $( elm ).attr( 'data-i10nk' );
					if ( typeof tr_key !== "undefined" && tr_key.length > 0 ) {
						if ( typeof transl[currentLang][tr_key] !== "undefined" ) {
							if (transl[currentLang][tr_key].length > 0) {
								$( elm ).html( transl[currentLang][tr_key] );
							}
						}
					}

					if (transl[currentLang]['plugin_menu_name'].length > 0) {
						$( '#toplevel_page_b2pcloud_settings' ).find( '.wp-menu-name' ).html( transl[currentLang]['plugin_menu_name'] );
					}
				}
			);
		}

		/**
		 * SET INCLUDE DATABASE IN THE BACKUP ->
		 * SET INCLUDE DATABASE IN THE BACKUP ->
		 */
		$( '#wp2pcl_withmysql' ).on(
			'change',
			function ( e ) {
				e.preventDefault();
				$( '#setting-error-mysql-settings_updated' ).show();
				$.post(
					ajax_url + '&method=set_with_mysql',
					$( '#wp2_incl_db_form' ).serialize(),
					function () {
						$.get( 'admin.php' );
					},
					'JSON'
				);
			}
		);

		/**
		 * EXCLUSIONS ( files / folders picker + DB tables ) ->
		 * The two hidden textareas are the source of truth that gets POSTed; the picker,
		 * the pattern box and the chip lists only edit them.
		 */
		const exclForm = $( '#wp2pcl_exclusions_form' );
		if ( exclForm.length ) {
			const filesField  = $( '#wp2pcl_exclude_files' );
			const tablesField = $( '#wp2pcl_exclude_tables' );
			const filesList   = $( '#wp2pcl_excl_files_list' );
			const tablesList  = $( '#wp2pcl_excl_tables_list' );
			const dirList     = $( '#wp2pcl_dir_list' );
			const dirCrumbs   = $( '#wp2pcl_dir_crumbs' );
			const tableSelect = $( '#wp2pcl_table_select' );
			let exclFiles     = parseLines( filesField.val() );
			let exclTables    = parseLines( tablesField.val() );
			let currentDir    = '';

			function parseLines( v ) {
				return String( v || '' ).split( /\r\n|\r|\n/ ).map( function ( x ) { return x.trim(); } ).filter( function ( x ) { return x.length > 0; } );
			}

			function normalizePattern( p ) {
				return String( p || '' ).trim().replace( /\\/g, '/' ).replace( /^(\.\/|\/)+/, '' ).replace( /\/+$/, '' );
			}

			function isCovered( path ) {
				return exclFiles.some( function ( p ) { return p === path || path.indexOf( p + '/' ) === 0; } );
			}

			function mutedRow( text ) {
				return $( '<li class="wp2pcl-muted"></li>' ).text( text );
			}

			function renderChips( list, items, kind ) {
				list.empty();
				if ( ! items.length ) {
					list.append( mutedRow( __( 'nothing_excluded', 'Nothing excluded yet.' ) ) );
					return;
				}
				items.forEach( function ( item ) {
					const li = $( '<li class="wp2pcl-chip"></li>' ).append( $( '<code></code>' ).text( item ) );
					li.append(
						$( '<button type="button" class="wp2pcl-chip-remove">&times;</button>' )
							.attr( { 'data-kind': kind, 'data-value': item, 'title': __( 'remove', 'Remove' ), 'aria-label': __( 'remove', 'Remove' ) } )
					);
					list.append( li );
				} );
			}

			function markEntry( li ) {
				const covered = isCovered( li.attr( 'data-path' ) );
				li.toggleClass( 'wp2pcl-excluded', covered );
				li.find( '.wp2pcl-exclude-add' ).prop( 'disabled', covered ).text( covered ? __( 'excluded_lbl', 'excluded' ) : __( 'exclude_btn', 'Exclude' ) );
			}

			function syncState() {
				filesField.val( exclFiles.join( '\n' ) );
				tablesField.val( exclTables.join( '\n' ) );
				renderChips( filesList, exclFiles, 'file' );
				renderChips( tablesList, exclTables, 'table' );
				tableSelect.find( 'option' ).each( function () {
					const v = $( this ).val();
					if ( v ) {
						$( this ).prop( 'disabled', exclTables.indexOf( v ) !== -1 );
					}
				} );
				tableSelect.val( '' );
				dirList.find( 'li[data-path]' ).each( function () { markEntry( $( this ) ); } );
			}

			function addFile( p ) {
				p = normalizePattern( p );
				if ( ! p || /^[*?\/]+$/.test( p ) || p.indexOf( '..' ) !== -1 ) {
					return;
				}
				if ( exclFiles.indexOf( p ) === -1 ) {
					exclFiles.push( p );
					persist();
				} else {
					syncState();
				}
			}

			function removeItem( kind, value ) {
				if ( kind === 'file' ) {
					exclFiles = exclFiles.filter( function ( x ) { return x !== value; } );
				} else {
					exclTables = exclTables.filter( function ( x ) { return x !== value; } );
				}
				persist();
			}

			function fmtSize( b ) {
				if ( b >= 1073741824 ) { return ( b / 1073741824 ).toFixed( 1 ) + ' GB'; }
				if ( b >= 1048576 ) { return ( b / 1048576 ).toFixed( 1 ) + ' MB'; }
				if ( b >= 1024 ) { return Math.round( b / 1024 ) + ' KB'; }
				return b + ' B';
			}

			function loadDir( path ) {
				dirList.html( mutedRow( __( 'loading', 'Loading…' ) ) );
				$.getJSON(
					ajax_url + '&method=list_dir&path=' + encodeURIComponent( path ) + '&wp2pcl_nonce=' + wp2pcl_nonce,
					function ( data ) {
						if ( ! data || data.status !== 0 ) {
							dirList.html( mutedRow( __( 'invalid_resp_srv', 'Invalid response from the server:' ) ) );
							return;
						}
						currentDir = data.path || '';

						dirCrumbs.empty().append( $( '<a href="#" data-path=""></a>' ).text( '/' ) );
						let acc = '';
						currentDir.split( '/' ).filter( Boolean ).forEach( function ( seg ) {
							acc = acc ? acc + '/' + seg : seg;
							dirCrumbs.append( ' › ' ).append( $( '<a href="#"></a>' ).attr( 'data-path', acc ).text( seg ) );
						} );

						dirList.empty();
						if ( currentDir ) {
							const up = currentDir.split( '/' ).slice( 0, -1 ).join( '/' );
							dirList.append( $( '<li class="wp2pcl-dir-up"></li>' ).append( $( '<a href="#"></a>' ).attr( 'data-path', up ).text( '‹ ' + __( 'folder_up', 'Up one level' ) ) ) );
						}
						const entries = data.entries || [];
						entries.forEach( function ( e ) {
							const full = currentDir ? currentDir + '/' + e.name : e.name;
							const li   = $( '<li></li>' ).attr( 'data-path', full ).addClass( e.type === 'dir' ? 'wp2pcl-dir' : 'wp2pcl-file' );
							if ( e.type === 'dir' ) {
								li.append( $( '<a href="#" class="wp2pcl-entry-name"></a>' ).attr( 'data-path', full ).text( e.name + '/' ) );
							} else {
								li.append( $( '<span class="wp2pcl-entry-name"></span>' ).text( e.name ) )
									.append( $( '<span class="wp2pcl-entry-size"></span>' ).text( fmtSize( e.size || 0 ) ) );
							}
							li.append( $( '<button type="button" class="button button-small wp2pcl-exclude-add"></button>' ).attr( 'data-path', full ) );
							markEntry( li );
							dirList.append( li );
						} );
						if ( ! entries.length ) {
							dirList.append( mutedRow( __( 'empty_folder', 'Empty folder' ) ) );
						}
						if ( data.truncated ) {
							dirList.append( mutedRow( __( 'list_truncated', 'Only the first 1500 entries are shown.' ) ) );
						}
					}
				).fail( function () {
					dirList.html( mutedRow( __( 'invalid_resp_srv', 'Invalid response from the server:' ) ) );
				} );
			}

			exclForm.on( 'click', 'a[data-path]', function ( e ) { e.preventDefault(); loadDir( $( this ).attr( 'data-path' ) ); } );
			exclForm.on( 'click', '.wp2pcl-exclude-add', function ( e ) { e.preventDefault(); addFile( $( this ).attr( 'data-path' ) ); } );
			exclForm.on( 'click', '.wp2pcl-chip-remove', function ( e ) { e.preventDefault(); removeItem( $( this ).attr( 'data-kind' ), $( this ).attr( 'data-value' ) ); } );
			$( '#wp2pcl_pattern_add' ).on( 'click', function ( e ) { e.preventDefault(); addFile( $( '#wp2pcl_pattern_input' ).val() ); $( '#wp2pcl_pattern_input' ).val( '' ); } );
			$( '#wp2pcl_pattern_input' ).on( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); $( '#wp2pcl_pattern_add' ).trigger( 'click' ); } } );
			$( '#wp2pcl_table_add' ).on( 'click', function ( e ) {
				e.preventDefault();
				const t = tableSelect.val();
				if ( t && exclTables.indexOf( t ) === -1 ) {
					exclTables.push( t );
					persist();
				} else {
					syncState();
				}
			} );

			// Every add / remove is saved straight away; there is no Save button.
			let savedTimer = null;
			function persist() {
				syncState();
				$.post(
					ajax_url + '&method=set_exclusions',
					exclForm.serialize(),
					function ( data ) {
						if ( data && data.status === 0 ) {
							exclFiles  = data.exclude_files || [];
							exclTables = data.exclude_tables || [];
							syncState();
							const notice = $( '#setting-error-exclusions_updated' ).stop( true, true ).show();
							clearTimeout( savedTimer );
							savedTimer = setTimeout( function () { notice.fadeOut( 300 ); }, 1500 );
						}
					},
					'JSON'
				);
			}

			exclForm.submit( function ( e ) { e.preventDefault(); } );

			syncState();
			loadDir( '' );
		}

		/**
		 * SET SCHEDULE INTERVAL ->
		 * SET SCHEDULE INTERVAL ->
		 */
		$( '#wp2pcloud_form' ).submit(
			function ( e ) {
				e.preventDefault();
				$( '#setting-error-settings_updated' ).show();
				$.post(
					ajax_url + '&method=set_schedule',
					$( this ).serialize(),
					function () {
						$.get( 'admin.php' );
					},
					'JSON'
				);
			}
		);

		/**
		 * RESTORE PROCEDURE ->
		 * RESTORE PROCEDURE ->
		 */
		$( 'body' ).on(
			'click',
			'.backup-file',
			(e) =>
			{
				let btn = $( e.currentTarget );

				btn.attr( 'disabled', 'disabled' );
				btn.addClass( 'disabled' );

				let fileId   = parseInt( btn.attr( 'data-file-id' ) );
				let folderId = parseInt( btn.attr( 'data-folder-id' ) );

				if ( isNaN( fileId ) ) {
					fileId = 0;
				}

				if ( isNaN( folderId ) ) {
					folderId = 0;
				}

				if (confirm( 'Are you sure?' )) {
					$.getJSON(
						ajax_url + "&method=check_can_restore",
						(data) =>
						{
							if (data['status'] !== "undefined") {
								if (parseInt( data['status'] ) === 0) {
									$.post(
										ajax_url + '&method=restore_archive',
										{
											'file_id': fileId,
											'folder_id': folderId,
											'wp2pcl_nonce': wp2pcl_nonce
										}
									);
								} else {
									$( '#message' ).html( data['msg'] );

									btn.attr( 'disabled', false );
									btn.removeClass( 'disabled' );
								}
							}
						}
					);
				}
			}
		);

		/**
		 * BACKUP PROCEDURE ->
		 * BACKUP PROCEDURE ->
		 */
		let makeBackUpBtn         = $( '#run_wp_backup_now' );
		makeBackUpBtn.on(
			'click',
			() =>
			{
				backupOffsetChunk = 0;

				backupLogWin.show();

				if (makeBackUpBtn.hasClass( 'disabled' )) {
					return false;
				}

				makeBackUpBtn.attr( 'disabled', 'disabled' );
				makeBackUpBtn.addClass( 'disabled' );

				if (makeBackUpBtn.hasClass( 'disabled' )) {
					$.post(
						ajax_url + '&method=start_backup',
						{
							'wp2pcl_nonce': wp2pcl_nonce
						},
						() =>
						{
							makeBackUpBtn.attr( 'disabled', false );
							makeBackUpBtn.removeClass( 'disabled' );
						}
					);
				}

				$( 'html, body' ).animate(
					{
						scrollTop: 0
					},
					'slow'
				);
			}
		);

		makeBackUpBtn.on(
			'dblclick',
			() =>
			{
				$.post(
					ajax_url + '&method=start_backup',
					{
						'wp2pcl_nonce': wp2pcl_nonce
					}
				);
			}
		);

		/**
		 * CHECK ACTIVITY ->
		 * CHECK ACTIVITY ->
		 */
		let backupLogWin = $( '.log_show' );

		let pollInFlight = false; // one get_log poll at a time per page, whatever calls us
		function pclCheckActivity( recheck )
		{
			if ( pollInFlight ) {
				return;
			}
			pollInFlight = true;
			$.ajax(
				{
					url: ajax_url + "&method=get_log&dbg=" + wp2pcl_debugmode + "&wp2pcl_nonce=" + wp2pcl_nonce,
					dataType: 'json',
					method: 'GET',
					timeout: 60000 // 30 second timeout
				}
			).error(
				function ( a, b ) {
					console.warn( 'Error reading the log: ', a, b );
				}
			).done(
				function (data) {

					log_reader_timer = 2000;

					backupLogWin.html( data.log );

					if (parseInt( data['hasactivity'] ) === 0) {
						backupLogWin.hide();
					} else {
						backupLogWin.show();
					}

					if ( typeof data['operation'] !== "undefined" && typeof data['operation']['operation'] !== "undefined" ) {
						if ( data['operation']['operation'] === 'upload' || data['operation']['operation'] === 'download' ) {
							backupLogWin.show();
						}
					}

					if ( wp2pcl_debugmode === true ) {
						backupLogWin.show();
					}

					if (typeof data['perc'] !== "undefined") {

						let percHTML    = '';
						let percInt     = parseInt( data['perc'] );
						let backupBtns  = $( '.backup-file' );
						let hasActivity = false;

						if (typeof data['operation'] !== "undefined") {
							if (data['operation']['operation'] === 'upload') {
								percHTML         = '<div><span class="pcl_transl" data-i10nk="uploading2pcloud">Uploading to pCloud</span> ( ' + data['sizefancy'] + ' ): </div>'
								log_reader_timer = 500;
								hasActivity      = true;
							} else if (data['operation']['operation'] === 'download') {
								percHTML         = '<div><span class="pcl_transl" data-i10nk="downloadingFpcloud">Downloading from pCloud</span> ( ' + data['sizefancy'] + ' ): </div>';
								log_reader_timer = 500;
								hasActivity      = true;
							}
						}

						if (hasActivity) {
							makeBackUpBtn.attr( 'disabled', 'disabled' );
							makeBackUpBtn.addClass( 'disabled' );
							backupBtns.attr( 'disabled', 'disabled' );
							backupBtns.addClass( 'disabled' );
						} else {
							makeBackUpBtn.attr( 'disabled', false );
							makeBackUpBtn.removeClass( 'disabled' );
							backupBtns.attr( 'disabled', false );
							backupBtns.removeClass( 'disabled' );
						}

						percHTML += '' +
							'<div class="d-flex pclprogressbar"><span class="pclpr-l">0% [&nbsp;&nbsp;</span><span class="pclpr-c">';

						let symbols = '';
						for (let i = 0; i < 101; i++) {
							if (i < percInt) {
								symbols += '===';
							}
						}

						let percWidth = parseFloat( data['perc'] ) - 5;
						if (percInt > 80) {
							percWidth = parseFloat( data['perc'] ) - 3;
						}
						if (percInt > 90) {
							percWidth = parseFloat( data['perc'] ) - 2;
						}
						if (percInt > 96) {
							percWidth = parseFloat( data['perc'] ) - 1;
						}

						percHTML += '<strong class="perc-line" style="width: ' + percWidth + '%">' + symbols + '</strong>';

						if ( percInt < 98 ) {
							percHTML += '<strong>=>&nbsp;[ ' + data['perc'] + '% ]</strong>';
						} else {
							percHTML += '<strong>=></strong>';
						}

						percHTML += '</span><span class="pclpr-r">&nbsp;&nbsp;] 100%</span></div>';
						backupLogWin.append( percHTML );

					}

					if (typeof data['quotaperc'] !== "undefined") {

						let quotaLeftPercent = parseFloat( data['quotaperc'] );

						let noSpaceDiv = $( '.pcl-low-spaceleft' );

						if (quotaLeftPercent < 90) {
							if ($( '#wp2pcloud-login-form' ).length < 1) {
								let incrSpaceLink = 'https://www.pcloud.com/' + currentLang + '/cloud-storage-pricing-plans.html?period=lifetime';
								if (noSpaceDiv.length < 1) {
									noSpaceDiv = $( '<div />' );
									noSpaceDiv.addClass( 'pcl-low-spaceleft' );
									noSpaceDiv.addClass( 'notice' );
									noSpaceDiv.addClass( 'notice-warning' );
									noSpaceDiv.addClass( 'is-dismissible' );
									noSpaceDiv.append( '<p><span class="pcl_transl" data-i10nk="no_space_left">You are running out of space! Click on the button below to increase your storage space!</span><br/><a href="' + incrSpaceLink + '" target="_blank" class="button">pCloud plans</a></p>' );
									noSpaceDiv.insertAfter( '#wp2pcloud-error' );
								}
							}
						}

						if (parseFloat( data['quotaperc'] ) > 90) {
							if (noSpaceDiv.length > 0) {
								noSpaceDiv.remove();
							}
						}
					}

					setTranslations();
				}
			).always(
				function () {
					pollInFlight = false;
					if ( typeof recheck !== "undefined" && recheck ) {
						window.setTimeout(
							function () {
								pclCheckActivity( true );
							},
							log_reader_timer
						);
					}
				}
			);

		}

		pclCheckActivity( true );

		/**
		 * UNLINK / LOGOUT FROM PCLOUD PROCEDURE ->
		 * UNLINK / LOGOUT FROM PCLOUD PROCEDURE ->
		 */
		$( '.wpb2pcloud_unlink_account' ).on(
			'click',
			function () {
				$.post(
					ajax_url + '&method=unlink_acc',
					{'wp2pcl_nonce': wp2pcl_nonce},
					function () {
						top.location.href = top.location.href.trim();
					}
				);
			}
		);

		/**
		 * TOGGLE DEBUG STATE ->
		 */
		$( '#pcl_dbg_tgl' ).on(
			'click',
			function () {
				let btn = $( this );
				if ( wp2pcl_debugmode === true ) {
					wp2pcl_debugmode = false;
					btn.css( 'color', '#CCC' );
					backupLogWin.hide();
				} else {
					wp2pcl_debugmode = true;
					btn.css( 'color', '#333' );
					pclCheckActivity();
					backupLogWin.show();
				}
			}
		);

		/**
		 * GET FREE SPACE ->
		 * GET FREE SPACE ->
		 */
		if ($( '#pcloud_info' ).length !== 0) {

			$.ajax(
				{
					url: ajax_url + "&method=userinfo&dbg=" + wp2pcl_debugmode + "&wp2pcl_nonce=" + wp2pcl_nonce,
					type: "GET",
					crossDomain: true,
					dataType: 'json',
					success: function (data) {
						if (typeof data['status'] === "undefined") {
							_display_error( 'No data received from the pCloud server!\n Please, try again later!' );
							window.setTimeout(
								function () {
									$( '.wpb2pcloud_unlink_account' ).trigger( 'click' );
								},
								30000
							);
						} else if (typeof data['status'] !== "undefined" && parseInt( data['status'] ) !== 0) {
							_display_error( data['error'] );
							window.setTimeout(
								function () {
									$( '.wpb2pcloud_unlink_account' ).trigger( 'click' );
								},
								30000
							);
						} else {

							let free_space = data['data']['quota'] - data['data']['usedquota'];
							if ( free_space < 0 ) {
								free_space = 0;
							}

							let info_cnt = _humanFileSize( free_space, 1024 );
							info_cnt    += ' <span class="pcl_transl" data-i10nk="free_space_av"> free space available</span>,';
							info_cnt    += '<span style="padding-left: 10px">Account: ' + data['data']['email'] + '</span>';
							$( '#pcloud_info' ).html( info_cnt );

							setTranslations();
						}
					}
				}
			);
		}

		/**
		 * GET BACKUP FILES ->
		 * GET BACKUP FILES ->
		 */
		let backupsArea          = $( '#pcloudListBackups' );
		let getBackupsFromPcloud = function () {

			if (backupsArea.length === 0) {
				return false;
			}

			$.getJSON(
				ajax_url + "&method=listfolder&dbg=" + wp2pcl_debugmode + "&wp2pcl_nonce=" + wp2pcl_nonce,
				{},
				(data) =>
				{
					if ( typeof data.status !== "undefined" && parseInt( data.status ) === 0 && typeof data.contents !== "undefined" ) {

						backupsArea.html( '' );

						for ( const [, item] of Object.entries( data.contents ) ) {

							let foundItemType = null;
							if ( item['name'].match( /\d{1,2}\.\d{1,2}\.\d{4}$/ ) ) {
								foundItemType = 'folder';
							} else if (item['contenttype'] === "application/zip" || typeof data['folderid'] !== "undefined") {
								foundItemType = 'file';
							} else {
								continue;
							}

							let myDate  = new Date( item['created'] );
							let dformat = myDate.toLocaleDateString() + " " + myDate.toLocaleTimeString();

							let download_link;
							if ( foundItemType === 'folder' ) {
								download_link = 'https://my.pcloud.com/#folder=' + item['folderid'] + '&page=filemanager';
							} else {
								download_link = 'https://my.pcloud.com/#file=' + data['folderid'] + '&page=filemanager';
							}

							const html = '<tr>' +
								'<td><a target="blank_" href="' + download_link + '">' + dformat + '</a></td>' +
								'<td>' + item['name'] + '</td>' +
								'<td><button type="button" data-file-id="' + item['fileid'] + '" data-folder-id="' + item['folderid'] + '" data-file-size="' + item['size'] + '" ' +
								'       class="button backup-file pcl_transl" data-i10nk=\'restore_backup\'>Restore backup</button></td>' +
								'<td><a href="' + download_link + '" target="_blank" class="button pcl_transl" data-i10nk=\'download\'>Download</a></td>' +
								'</tr> ';
							backupsArea.append( html );
						}
					}
				}
			);
			setTimeout( getBackupsFromPcloud, 30000 );
		};

		getBackupsFromPcloud();

		/**
		 * Display human-readable file size
		 *
		 * @param {number} bytes
		 * @param {number} si
		 *
		 * @returns {string}
		 * @private
		 */
		function _humanFileSize(bytes, si)
		{
			let thresh = si ? 1024 : 1024;
			if (bytes < thresh) {
				return bytes + ' B';
			}
			let units = si ? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'] : ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
			let u     = -1;
			do {
				bytes /= thresh;
				++u;
			} while (bytes >= thresh);
			return bytes.toFixed( 1 ) + ' ' + units[u];
		}

		/**
		 * Display error message.
		 *
		 * @param {string} msg
		 *
		 * @private
		 */
		let _display_error = function ( msg ) {
			let errorBlk = $( '#wp2pcloud-error' );
			errorBlk.find( 'p' ).html( msg );
			errorBlk.show();

			window.setTimeout(
				function () {
					errorBlk.hide( 'fast' );
				},
				10000
			);
		}
	}
);

} )();
