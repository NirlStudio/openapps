/*
 * minifx.js
 * 
 * 2013, Nirl Studio. No Rights Reserved.
 */
(function($){
    
    window.$minifx = {
        _views    : {}, // map a view's name to its meta info.
        _services : {}  // map a service to its views.
    };
    
    $minifx.addView = function(name, svc, ctlr, stamp) {
        
        if ( name == null || svc == null || ctlr == null )
            return; // not to accept view missing required information.
        
        if ( stamp == null ) // no local-cached data
            stamp = { 'latest' : 0 }; 
            
        // construct an entry object for this view.
        $minifx._views[name] = {
            svc    : svc  , // the service uri.
            ctlr   : ctlr , // the view controller: function(name, type, status, result) {}.
            action : '_u' , // default action is 'Update'.
            stamp  : stamp, // the current state/version of view data.
            more   : false, // whether having more rows.
            
            // method names mapping to MiniXXXService based services.
            _u : name.concat(':_u'), // Update
            _c : name.concat(':_c'), // Check
            _q : name.concat(':_q'), // Query
            _m : name.concat(':_m')  // More
        };
        
        // merge views for the same service.
        var views = $minifx._services[svc];
        if ( views == null ) 
            $minifx._services[svc] = [ name ];  // the first view for this service.
        else if ( views.indexOf(name) < 0 )
            views.push(name); // append the view into name list.
        // else, just do nothing.
    }

    $minifx.hideView = function(name) {
        
        // update the view's default action to 'Check'.
        var view = $minifx._views[name];
        if ( view != null )
            view.action = '_c';
    }

    $minifx.disableView = function(name) {
        
        // update the view's default action to 'do-nothing'.
        var view = $minifx._views[name];
        if ( view != null )
            view.action = '_d';// there is not a real action for '_d'.
    }

    $minifx.showView = function(name) {
        
        // restore the view's default action to 'Update'.
        var view = $minifx._views[name];
        if ( view != null )
            view.action = '_u';
    }

    $minifx.removeView = function(name) {
        
        // remove the view from view list and its service map.
        var view = $minifx._views[name];
        if ( view == null )
            return; // no found
        
        // remove it from view list.
        delete $minifx._views[name];
        
        // find related service.
        var views = $minifx._services[view.svc];
        if ( views == null )
            return;
            
        // remove the view name from service.
        var pos = views.indexOf(name);
        if ( pos < 0 ) 
            return; // not found
        else
            views.splice(pos, 1); // remove it
        
        // clear empty service map entry.
        if ( views.length < 1 )   // no available view for this service.
            delete $minifx._services[view.svc];
    }
    
    // just used by $minifx.update, $minifx.check and $minifx.query
    $minifx._exec = function(name, type) {
        
        var view = $minifx._views[name];
        if ( view == null )
            return false;
        
        // the 'type' just can one of '_u', '_c' and '_q'.
        $minifx._post(view.svc, view[type], { 'stamp': view.stamp });
        return true;
    }
    
    $minifx.update = function(name) {
        // Update : check firstly, then query if checking succeeded.
        return $minifx._exec(name, '_u');
    }
    
    $minifx.check = function(name) {
        // Check : just to check whether there is any update.
        return $minifx._exec(name, '_c');
    }
    
    $minifx.query = function(name) {
        // Query : query for the diff/complete data for this view.
        return $minifx._exec(name, '_q');
    }

    $minifx.has_more = function(name) {
        
        var view = $minifx._views[name];
        if ( view == null )
            return false;
        else
            return view.more;
    }
    
    $minifx.more = function(name, tag) {
        // More : query for the more data entries for this view.
        var view = $minifx._views[name];
        if ( view == null )
            return false;
        
        if ( view.more )
            $minifx._post(view.svc, view['_m'], { 'tag': tag });
        return true;
    }
    
    $minifx.refresh = function() {
        
        // to perform the dafault actions for views of each service.
        for ( var svc in $minifx._services ) {
             
            var views = $minifx._services[svc];
            var actions = [];
            for ( var vi in views ) {
                
                var view = $minifx._views[views[vi]];
                var action = view[view.action];
                if ( action == null ) // for '_d' or other unknown action names.  
                    continue;         // do nothing.
                    
                actions.push({
                    _m : action, // action
                    _d : {       // data stamp
                        'stamp' : view.stamp
                    } 
                });
            }
            
            if ( actions.length > 1 ) // more than one action.
                $minifx._post(svc, '_exec', actions); // batch execution
            else if ( actions.length > 0 ) // just one action
                $minifx._post(svc, actions[0]._m, actions[0]._d);
            // else, do nothing for this service.
        }
    }
    
    $minifx._timer_id = null; // interval id
    $minifx._pending = [];    // pending and queued requests
    
    // to perform default actions periodically.
    $minifx.start = function(interval) {
        
        if ( $minifx._timer_id != null )
            return; // a live timer is existing.
            
        $minifx._timer_id = setInterval(
            function(){
                // refresh when there is not pending or queued request.
                if ( $minifx._pending.length < 1 ) 
                    $minifx.refresh(); 
            // else, skip this round.
            }, 
            interval
        );
    }
    
    $minifx.stop = function() {
        
        if ( $minifx._timer_id == null )
            return; // no live timer.
        
        // clear all pending or queued requests.
        $minifx._pending = []; 
        
        // remove timer
        clearInterval($minifx._timer_id);
        $minifx._timer_id = null;
    }
    
    // could be replaced by application code.
    $minifx.fail = function(status, svc, action, data) {
        
        // by default, just log it if there exists an available console object.
        if ( window.console && window.console.log )
            console.log('minifx: '.concat(status, ' : ', svc, ' : ', action, ' : ', data));
    }
    
    $minifx._post = function(svc, action, data) {
        
        if ( svc == null || action == null )
            return; // invalid arguments
        
        // save request into pending queue. 
        $minifx._pending.push({
            'svc'   : svc, 
            'action': action, 
            'data'  : data
        });
        
        // try to send
        if ( $minifx._pending.length == 1 ) // if it's the only request,
            $minifx._postAction(svc, action, data);// send the request.
    }
    
    $minifx._postNext = function() {
        
        if ( $minifx._pending.length < 1 )
            return; // it might be cleared already.
                
        // otherwise, remove the first request for it's the pending one.
        $minifx._pending.splice(0, 1);
        if ( $minifx._pending.length < 1 )
            return; // no more queued request.
        
        // to send the next queued one.
        var req = $minifx._pending[0];
        $minifx._postAction(req.svc, req.action, req.data);
    }
    
    $minifx._postAction = function(svc, action, data) { 
        
        // to send the request in a POST message by the JQuery.ajax()
        $.post(
            svc.concat('?_m=', action), // compose the query string.
            JSON.stringify(data),       // place data into request's body.
            function(data, status) {    // success callback
                
                if ( $minifx._pending.length < 1 )
                    return;// it might have been cleared by $minifx.stop().
                     
                // retrieve original request information.
                var req = $minifx._pending[0];
                
                // dispatch result to view controller
                if ( req.action == '_exec' )          // for batch execution,
                    $minifx._expand(status, data);  // to expand result set.
                else                                  // otherwise, 
                    $minifx._done(status, req.action, data);  // invoke success callback
            },
            'json') // returns an XHR object
        .fail(function(xhr, status) {
            
            if ( $minifx._pending.length > 0 ) {
                
                var req = $minifx._pending[0];
                $minifx.fail(status, req.svc, req.action, req.data); 
            }
            
        }) // returns the XHR object
        .always(function() {
            
            // clear current request and try to send next queued one.
            $minifx._postNext();   
        });
    }
    
    $minifx._expand = function(status, result) {
        
        // dispatch all result items.
        for(var item in result ) 
            $minifx._done(status, item._m, item._d);
    }
    
    $minifx._done = function(status, action, result) {
    
        // retrieve the view name from action name.
        var pos = action.lastIndexOf(':');
        var name = action.substr(0, pos);
        var type = action.substr(pos+1);

        // lookup original view object.
        var view = $minifx._views[name];
        if ( view == null || view.ctlr == null )
            return; // no view or invalid view entry for any reason.
        
        if ( result.status != 200 ) {
            
            $minifx.fail(result.status, name, type, result);
            return;
        }
        
        if ( type == '_u' || type == '_q' ) {
        
            if ( result.updated === false ) 
                return;
                
            var stamp = result.stamp;
            if ( stamp == null )// if the server does not support incremental update, 
                stamp = { 'latest' : 0 }; // it will cause another full update in next round.
            view.stamp = stamp; // update auto-managed view state.
        }   
            
        if ( result.more != undefined )
            view.more = result.more ? true : false;
            
        // to inform the view controller
        view.ctlr(name, type, status, result);
    }

})(window.jQuery);
