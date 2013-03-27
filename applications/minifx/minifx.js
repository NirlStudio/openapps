/*
 * minifx.js
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */
(function(){
	
	window.$minifx = {
		_views    : {} , // map view's name to its meta info.
		_services : {}   // map service to its views.
	} ;
	
	$minifx.addView = function(name, svc, ctlr, stamp) {
		
		if ( name == null || svc == null || ctlr == null )
			return ; // not to accept view missing required information.
		
		if ( stamp == null ) // no local-cached data
			stamp = {} ; 
			
		// construct an entry object for this view.
		$minifx._views[name] = {
			svc    : svc   , // the service uri.
			ctlr   : ctlr  , // the view controller: function(name, type, status, result) {}.
			action : '_u'  , // default action is 'Update'.
			stamp  : stamp , // the current stamp of view's data.
			
			// method names mapping to MiniXXXService based services.
			_u : name.concat(':_u') , // Update
			_c : name.concat(':_c') , // Check
			_q : name.concat(':_q')   // Query
		} ;
		
		// merge views for the same service.
		var views = $minifx._services[svc] ;
		if ( views == null ) 
			$minifx._services[svc] = [ name ] ;  // the first view for this service.
		else if ( views.indexOf(name) == -1)
			views.push(name) ; // append the view into list
		// else do nothing.
	}

	$minifx.hideView = function(name) {
		
		// update the view's default action to 'Check'.
		var view = $minifx._views[name] ;
		if ( view != null )
			view.action = '_c' ;
	}

	$minifx.disableView = function(name) {
		
		// update the view's default action to 'do-nothing'.
		var view = $minifx._views[name] ;
		if ( view != null )
			view.action = '_d' ;
	}

	$minifx.showView = function(name) {
		
		// restore the view's default action to 'Update'.
		var view = $minifx._views[name] ;
		if ( view != null )
			view.action = '_u' ;
	}

	$minifx.removeView = function(name) {
		
		// remove the view from view list and it's service.
		var view = $minifx._views[name] ;
		if ( view == null )
			return ; // no found
		
		// remove it from view list.
		delete $minifx._views[name] ;
		
		// find related service.
		var views = $minifx._services[view.svc] ;
		if ( views == null )
			return ;
			
		// remove the name from service.
		var pos = views.indexOf(name) ;
		if ( pos == -1 ) return ; // not found
		views.splice(pos, 1) ;    // remove it
		
		// clear empty service entry.
		if ( views.length < 1 )   // no available view for this service.
			delete $minifx._services[view.svc] ;
	}
	
	$minifx._exec = function(name, type) {
		
		var view = $minifx._views[name] ;
		if ( view == null )
			return false ;
		
		// since the 'type' just could be '_u', '_c' or '_q', ...
		$minifx._post(view.svc, view[type], view.stamp) ;
		return true ;
	}
	
	$minifx.update = function(name) {
		// update : check firstly, then query if check succeeds.
		return $minifx._exec(name, '_u') ;
	}
	
	$minifx.check = function(name) {
		// check : just to check whether there is any update.
		return $minifx._exec(name, '_c') ;
	}
	
	$minifx.query = function(name) {
		// query : query for the diff data for this view.
		return $minifx._exec(name, '_q') ;
	}
	
	$minifx.refresh = function() {
		
		// to do the dafault action for views of each service.
		for ( var svc in $minifx._services ) { 
		
			var views = $minifx._services[svc] ;
			var actions = [] ;
			for ( var view in views ) {
				
				var action = view[view.action] ;
				if ( action == null ) // for '_d',  
					continue ;        // do nothing.
					
				actions.push({
					_m : action ,     // action
					_d : view.stamp   // data stamp
				}) ;
			}
			
			if ( actions.length > 1 ) // more than one action.
				$minifx._post(svc, '_exec', actions) ; // batch execution
			else if ( actions.length > 0 ) // just one action
				$minifx._post(svc, actions[0]._m, actions[0]._d) ;
			// else do nothing
		}
	}
	
	$minifx._timer_id = null ; // interval id
	$minifx._pending = [] ;    // pending and queued requests
	
	// to do default action periodically.
	$minifx.start = function(interval) {
		
		if ( $minifx._timer_id != null )
			return ; // there is a live timer.
		
		$minifx._timer_id = setInterval(function(){
			
			if ( $minifx._pending.length < 1 ) 
				$minifx.refresh(); // when there is not pending or queued request.
			// else - skip this round.
		}, interval);
	}
	
	$minifx.stop = function() {
		
		if ( $minifx._timer_id == null )
			return ; // no live timer.
		
		// clear all pending or queued requests.
		$minifx._pending = [] ; 
		
		// remove timer
		clearInterval($minifx._timer_id) ;
		$minifx._timer_id = null ;
	}
	
	// could be replaced by application code.
	$minifx.fail = function(status, svc, action, data) {
		
		// by default, just log it if there is an available console object.
		if ( window.console && window.console.log )
			console.log('minifx: '.concat(status, ':', svc, ':', action, ':', data));
	}
	
	$minifx._post = function(svc, action, data) {
		
		if ( svc == null || action == null )
			continue ; // invalid arguments
		
		// save request into pending queue. 
		$minifx._pending.push({svc:svc, action:action, data:data});
		
		// try to send
		if ( $minifx._pending.length == 1 ) // if it's the only request,
			$minifx._postAction(svc, action, data) ;// send the request.
	}
	
	$minifx._postNext = function() {
		
		if ( $minifx._pending.length < 1 )
			return ; // it might be cleared already.
				
		// otherwise, remove the first request - the pending one.
		$minifx._pending.splice(0, 1) ;
		if ( $minifx._pending.length < 1 )
			return ; // no more queued request.
		
		// to send the next queued one.
		var req = $minifx._pending[0] ;
		$minifx._postAction(req.svc, req.action, req.data) ;
	}
	
	$minifx._postAction = function(svc, action, data) { 
		
		// to send the request in a POST message by the JQuery.ajax()
		$.post(
			svc.concat('?_m=', action) , // compose the query string.
			data ,                       // place data into request's body.
			function(data, status) {     // success callback
				
				if ( $minifx._pending.length < 1 )
					return ;// it might have been cleared by $minifx.stop().
				
				// just accept JSON body by design.
				var result = $.parseJSON(data) ; 
				// retrieve original request information.
				var req = $minifx._pending[0] ;
				
				// dispatch result to view's controller
				if ( req.action == '_exec' )          // for batch execution,
					$minifx._expand(status, result) ; // to expand result set.
				else                                  // otherwise, 
					$minifx._done(status, req.action, result) ;  // invoke success callback
			} ,
			'json') // returns an XHR object
		.fail(function(xhr, status) {
			
			if ( $minifx._pending.length > 0 ) {
				
				var req = $minifx._pending[0] ;
				$minifx.fail(status, req.svc, req.action, req.data); 
			}
			
		}) // returns an XHR object
		.always(function() {
			
			// clear current request and try to send next queued one.
			$minifx._postNext();   
		}) ;
	}
	
	$minifx._expand = function(status, result) {
/*
	result is an array which likes:
 	[
		{
			_m : 'xxx' ,
			_d : {
				status: app-status-code , 
				stamp : 'data-stamp' , 
				... // more app data
			}
		}, 
		... // more result entry.
	] */
		// dispatch all result items.
		for(var item in result ) 
			$minifx._done(status, item._m, item._d);
	}
	
	$minifx._done = function(status, action, result) {
/**
	result should be an object which likes: 
	{
		status : app-status-code , 
		stamp : 'data-stamp' , 
		... // more app data
	} */
	
		// retrieve the view name from action.
		var pos = action.lastIndexOf(':') ;
		var name = action.substr(0, pos) ;
		var type = action.substr(pos+1) ;
		
		// lookup original view object.
		var view = $minifx._views[name] ;
		if ( view == null || view.ctrl == null )
			return ; // no view or invalid view entry for any reason.
			
		// to inform the view controller
		if ( view.ctlr(name, type, status, result) ) {
			
			// since the view controller has saved the data successfully.
			var stamp = result.stamp ;
			if ( stamp == null ) // if the server does not support versioning model, 
				stamp = {} ;     // it will cause another full update in next round.
			view.stamp = stamp ; // update auto-managed view's state.
		}
	}

})() ;
