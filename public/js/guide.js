/* One readable guide for the signed-in role, with exact main-page control locations. */
(() => {
    const titles={staff:'Staff guide',admin:'Admin guide'};
    window.refreshRoleGuide=()=>{
        const user=window.currentUser,role=user?.role;
        let active=null;
        document.querySelectorAll('[data-role-guide]').forEach(guide=>{
            const selected=guide.dataset.roleGuide===role;
            guide.hidden=!selected;guide.classList.toggle('d-none',!selected);
            guide.querySelectorAll('[data-guide-requires],[data-guide-requires-any]').forEach(node=>{
                const all=(node.dataset.guideRequires||'').split(/\s+/).filter(Boolean);
                const any=(node.dataset.guideRequiresAny||'').split(/\s+/).filter(Boolean);
                node.hidden=!all.every(key=>!!user?.[key])||(any.length>0&&!any.some(key=>!!user?.[key]));
            });
            if(selected)active=guide;
        });
        const title=titles[role]||'Your guide';
        for(const id of ['systemGuideTitle','systemGuideNavigationLabel']){const node=document.getElementById(id);if(node)node.textContent=title;}
        const description=document.getElementById('guideDescription');
        if(description)description.textContent=active?.dataset.guideDescription||'Find the controls you need on the main page.';
        return active;
    };
    document.addEventListener('DOMContentLoaded',()=>{
        window.refreshRoleGuide();
        document.querySelectorAll('[data-role-guide]').forEach(guide=>{
            guide.querySelectorAll('.role-guide-detail').forEach(details=>details.addEventListener('toggle',()=>{
                if(details.open)guide.querySelectorAll('.role-guide-detail').forEach(other=>{if(other!==details)other.open=false;});
            }));
        });
        const modal=document.getElementById('systemGuideModal');
        modal?.addEventListener('show.bs.modal',()=>{
            const guide=window.refreshRoleGuide();
            guide?.querySelectorAll('details').forEach(details=>{details.open=false;});
            const body=modal.querySelector('.modal-body');if(body)body.scrollTop=0;
        });
        modal?.addEventListener('shown.bs.modal',()=>{
            document.querySelector('[data-role-guide]:not([hidden]) .role-guide-heading')?.focus({preventScroll:true});
        });
        modal?.addEventListener('hidden.bs.modal',()=>{
            document.querySelectorAll('[data-role-guide] details').forEach(details=>{details.open=false;});
        });
    });
})();
